<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Entity\MovieList;
use App\Entity\MovieListEntry;
use App\Entity\User;
use App\Repository\MovieListRepository;
use App\Service\Import\Importers\ListImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A list is a snapshot, like the watchlist: every import says what the list holds today, so
 * these are about what a second import does to the first one's rows.
 *
 * Film links are full letterboxd.com URLs rather than boxd.it codes, as in the other importer
 * tests, so nothing here reaches the network.
 */
final class ListImporterTest extends KernelTestCase
{
    private const LIST_URL = 'https://boxd.it/zzListTest';

    private EntityManagerInterface $entityManager;
    private User $user;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('list-import@example.com', 'Listes');
        $this->user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function testAnExportBecomesAListWithItsFilmsInOrder(): void
    {
        $batch = $this->import('Films français', ['zz-la-haine', 'zz-samba', 'zz-the-artist'], description: 'À voir un jour');

        self::assertSame(3, $batch->getRowsImported());

        $list = $this->storedList();
        self::assertSame('Films français', $list->getName());
        self::assertSame('À voir un jour', $list->getDescription());
        self::assertSame('2026-10-11', $list->getCreatedOn()?->format('Y-m-d'));
        self::assertSame(['zz-la-haine', 'zz-samba', 'zz-the-artist'], $this->storedSlugs());
    }

    public function testASecondImportRefreshesTheListRatherThanAddingACopy(): void
    {
        // Renamed, reordered, one film taken off and one put on. The link is what says it is
        // still the same list; the name changing is exactly the case it is keyed on the link for.
        $this->import('Films français', ['zz-la-haine', 'zz-samba', 'zz-the-artist']);
        $this->import('Cinéma français', ['zz-the-artist', 'zz-la-haine', 'zz-chocolat']);

        $lists = $this->entityManager->getRepository(MovieList::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $lists);
        self::assertSame('Cinéma français', $this->storedList()->getName());
        self::assertSame(['zz-the-artist', 'zz-la-haine', 'zz-chocolat'], $this->storedSlugs());
    }

    public function testAFilmThatFailedToResolveIsNotReadAsRemoved(): void
    {
        // A link that does not resolve this time is not a film that left the list. Pruning on
        // a partial read would delete it until the next import happened to put it back.
        $this->import('Films français', ['zz-la-haine', 'zz-samba']);

        $batch = $this->import('Films français', ['zz-la-haine'], extraRows: ['2,Introuvable,2001,https://example.com/pas-un-film/,']);

        self::assertSame(1, $batch->getRowsFailed());
        self::assertSame(['zz-la-haine', 'zz-samba'], $this->storedSlugs());
    }

    public function testAnEmptyListEmptiesItsStoredCopy(): void
    {
        // Unlike an empty watchlist.csv, an empty list file is not a broken one: Letterboxd
        // writes the list's own block and stops, and a list can genuinely hold nothing.
        $this->import('Films français', ['zz-la-haine']);
        $this->import('Films français', []);

        self::assertSame([], $this->storedSlugs());
    }

    public function testListsAreKeptPerAccount(): void
    {
        // The same link on two accounts is two lists: one person's import must not refresh,
        // and above all must not prune, somebody else's.
        $other = new User('list-import-other@example.com', 'Autre');
        $other->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->import('Films français', ['zz-la-haine', 'zz-samba'], owner: $other);
        $this->import('Films français', ['zz-the-artist']);

        $theirs = self::getContainer()->get(MovieListRepository::class)->findOneByUserAndUrl($other, self::LIST_URL);
        self::assertNotNull($theirs);
        self::assertCount(2, $theirs->getEntries());
    }

    public function testTheProgressBarCountsFilmsNotLines(): void
    {
        $path = $this->write('Films français', ['zz-la-haine', 'zz-samba']);

        // Seven lines in the file, two films on the list.
        self::assertSame(2, self::getContainer()->get(ListImporter::class)->countRows($path));
    }

    /**
     * @param list<string> $slugs
     * @param list<string> $extraRows raw film rows appended after the generated ones
     */
    private function import(string $name, array $slugs, ?string $description = null, array $extraRows = [], ?User $owner = null): ImportBatch
    {
        $path = $this->write($name, $slugs, $description, $extraRows);

        $batch = new ImportBatch($owner ?? $this->user, 'lists/films-francais.csv', $path, ImportFileType::LIST);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        self::getContainer()->get(ListImporter::class)->import($path, $batch);
        // What a fresh request would see, not the identity map this import just wrote into.
        $this->entityManager->clear();
        $this->user = $this->entityManager->find(User::class, $this->user->getId()) ?? $this->user;

        return $batch;
    }

    /**
     * @param list<string> $slugs
     * @param list<string> $extraRows
     */
    private function write(string $name, array $slugs, ?string $description = null, array $extraRows = []): string
    {
        $lines = [
            'Letterboxd list export v7',
            'Date,Name,Tags,URL,Description',
            sprintf('2026-10-11,%s,,%s,%s', $name, self::LIST_URL, $description ?? ''),
        ];

        if ([] !== $slugs || [] !== $extraRows) {
            $lines[] = '';
            $lines[] = 'Position,Name,Year,URL,Description';
            foreach ($slugs as $index => $slug) {
                $lines[] = sprintf('%d,%s,2014,https://letterboxd.com/film/%s/,', $index + 1, ucfirst($slug), $slug);
            }
            array_push($lines, ...$extraRows);
        }

        $path = tempnam(sys_get_temp_dir(), 'list').'.csv';
        file_put_contents($path, implode("\r\n", $lines)."\r\n");
        $this->paths[] = $path;

        return $path;
    }

    private function storedList(): MovieList
    {
        $list = self::getContainer()->get(MovieListRepository::class)->findOneByUserAndUrl($this->user, self::LIST_URL);
        self::assertNotNull($list);

        return $list;
    }

    /**
     * @return list<string> slugs in the list's own order
     */
    private function storedSlugs(): array
    {
        return array_values(array_map(
            static fn (MovieListEntry $entry) => (string) $entry->getMovie()->getLetterboxdSlug(),
            $this->storedList()->getEntries()->toArray()
        ));
    }
}
