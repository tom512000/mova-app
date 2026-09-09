<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\WatchlistEntry;
use App\Repository\MovieRepository;
use App\Service\Import\Importers\WatchlistImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * watchlist.csv is the only Letterboxd export file that is a snapshot rather than a log, so
 * it is the only one where what the file does *not* say carries information.
 *
 * Read as additive — which it was — the watchlist could only ever grow. Watching a film takes
 * it off the Letterboxd watchlist, so the next export stops naming it, and the row stayed
 * for ever: twenty-two of two hundred and seven stored entries were films already seen.
 */
final class WatchlistImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('watchlist-import@example.com', 'Watchlist');
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

    public function testAFilmTheExportStopsNamingLeavesTheWatchlist(): void
    {
        // The bug, in one scenario: you watch Heat, Letterboxd drops it from your watchlist,
        // and the next export simply does not mention it. Nothing else can tell the app.
        $this->import(['heat', 'dune']);
        self::assertSame(['dune', 'heat'], $this->storedSlugs());

        $this->import(['dune']);

        self::assertSame(['dune'], $this->storedSlugs());
    }

    public function testAnEmptyExportIsTreatedAsABrokenFileAndDeletesNothing(): void
    {
        // A snapshot naming nothing is not the claim "your watchlist is empty" — it is a file
        // that arrived wrong, and acting on it would take the whole watchlist with it.
        $this->import(['heat', 'dune']);

        $this->import([]);

        self::assertSame(['dune', 'heat'], $this->storedSlugs());
    }

    public function testAnEntryStillNamedKeepsTheDateItWasAddedOn(): void
    {
        // The prune must not become a delete-and-recreate: the date a film was added to the
        // watchlist is the only ordering the page has.
        $this->import(['dune'], '2020-01-02');
        $this->import(['dune'], '2020-01-02');

        $entries = $this->entityManager->getRepository(WatchlistEntry::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $entries);
        self::assertSame('2020-01-02', $entries[0]->getAddedDate()?->format('Y-m-d'));
    }

    public function testAnotherAccountsWatchlistIsLeftAlone(): void
    {
        // The prune deletes by absence, which makes the user filter on it load-bearing in a
        // way an additive importer's never was.
        $other = new User('somebody-else-watchlist@example.com', 'Autre');
        $other->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($other);

        $movie = new Movie('zz-wl-heat', 'Heat');
        $this->entityManager->persist($movie);
        $this->entityManager->persist(new WatchlistEntry($other, $movie));
        $this->entityManager->flush();

        $this->import(['dune']);

        $theirs = $this->entityManager->getRepository(WatchlistEntry::class)->findBy(['user' => $other]);
        self::assertCount(1, $theirs, 'pruning my watchlist must not touch anybody else\'s');
    }

    public function testAWatchedFilmStillOnTheListIsHiddenRatherThanDeleted(): void
    {
        // Between two exports, an RSS sync can record a viewing while the stored row is still
        // there. The repository hides it; only the next import removes it. Both are needed,
        // and this pins that the row genuinely survives until then.
        $this->import(['dune']);

        $movie = self::getContainer()->get(MovieRepository::class)->findOneByLetterboxdSlug('dune');
        self::assertNotNull($movie);
        $watch = new Watch($this->user, $movie, WatchSource::RSS_SYNC);
        $watch->setWatchedDate(new \DateTimeImmutable('2026-09-01'));
        $this->entityManager->persist($watch);
        $this->entityManager->flush();

        self::assertSame(['dune'], $this->storedSlugs(), 'the row is still there');
    }

    /**
     * @param list<string> $slugs
     */
    private function import(array $slugs, string $addedDate = '2019-05-05'): ImportBatch
    {
        $rows = ['Date,Name,Year,Letterboxd URI'];
        foreach ($slugs as $slug) {
            $rows[] = sprintf('%s,%s,2021,https://letterboxd.com/tom/film/%s/', $addedDate, ucfirst($slug), $slug);
        }

        $path = tempnam(sys_get_temp_dir(), 'watchlist').'.csv';
        file_put_contents($path, implode("\n", $rows)."\n");
        $this->paths[] = $path;

        $batch = new ImportBatch($this->user, 'watchlist.csv', $path, ImportFileType::WATCHLIST);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        self::getContainer()->get(WatchlistImporter::class)->import($path, $batch);

        return $batch;
    }

    /**
     * @return list<string>
     */
    private function storedSlugs(): array
    {
        $entries = $this->entityManager->getRepository(WatchlistEntry::class)->findBy(['user' => $this->user]);
        $slugs = array_map(static fn (WatchlistEntry $e) => $e->getMovie()->getLetterboxdSlug(), $entries);
        sort($slugs);

        return array_values($slugs);
    }
}
