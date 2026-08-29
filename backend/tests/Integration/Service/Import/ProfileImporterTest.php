<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Repository\LetterboxdProfileRepository;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\Importers\ProfileImporter;
use App\Service\Import\LetterboxdSlugExtractor;
use App\Service\Import\MovieUpserter;
use App\Service\Letterboxd\BoxdItResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * profile.csv, the one file in the export that is about a person rather than a list of films.
 *
 * The boxd.it resolver is stubbed throughout. Following those redirects for real is
 * BoxdItResolver's own business, and a test that reached the network to find out what
 * /1VEo points at would be testing Letterboxd rather than this importer.
 */
final class ProfileImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;
    private string $csvPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('profile@example.com', 'Profile');
        $this->user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        $this->csvPath = tempnam(sys_get_temp_dir(), 'profile').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function testTheProfileAndItsFourPinnedFilmsAreRead(): void
    {
        $this->write(
            '2025-04-14,tom51200,Tom,Sikora,tom@example.com,France,https://exemple.fr,Une bio.,He / his,'
            .'"https://boxd.it/aaa, https://boxd.it/bbb, https://boxd.it/ccc, https://boxd.it/ddd"'
        );

        $this->importer()->import($this->csvPath, $this->batch());

        $profile = self::getContainer()->get(LetterboxdProfileRepository::class)->findOneByUser($this->user);
        self::assertNotNull($profile);
        self::assertSame('tom51200', $profile->getUsername());
        self::assertSame('Tom Sikora', $profile->getFullName());
        self::assertSame('France', $profile->getLocation());
        self::assertSame('He / his', $profile->getPronoun());
        self::assertSame('Une bio.', $profile->getBio());
        self::assertSame('https://exemple.fr', $profile->getWebsite());
        self::assertSame('2025-04-14', $profile->getJoinedOn()?->format('Y-m-d'));

        // Slots, not a set: the order in the cell is the order on the profile.
        self::assertSame(
            ['film-aaa', 'film-bbb', 'film-ccc', 'film-ddd'],
            array_map(static fn ($f) => $f->getMovie()->getLetterboxdSlug(), $profile->getFavourites()->toArray())
        );
        self::assertSame([1, 2, 3, 4], array_map(static fn ($f) => $f->getPosition(), $profile->getFavourites()->toArray()));
    }

    public function testAnEmailAddressIsReadAndDeliberatelyDropped(): void
    {
        $this->write('2025-04-14,tom51200,,,secret@example.com,,,,,');

        $this->importer()->import($this->csvPath, $this->batch());

        // A second copy of somebody's email that no screen shows is a liability. Nothing in
        // the entity should be able to hold it.
        $stored = $this->entityManager->getConnection()
            ->executeQuery('SELECT * FROM letterboxd_profile')
            ->fetchAssociative();

        self::assertIsArray($stored);
        self::assertNotContains('secret@example.com', array_map('strval', $stored));
    }

    public function testAFavouriteAlreadyInTheLibraryIsReusedRatherThanDuplicated(): void
    {
        $existing = new Movie('film-aaa', 'WALL·E');
        $existing->setReleaseYear(2008);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $this->write('2025-04-14,tom51200,,,,,,,,https://boxd.it/aaa');

        $this->importer()->import($this->csvPath, $this->batch());

        $this->entityManager->clear();
        $movies = $this->entityManager->getRepository(Movie::class)->findBy(['letterboxdSlug' => 'film-aaa']);

        self::assertCount(1, $movies, 'the favourite must attach to the film already imported');
        // And its real title survives — the slug-derived placeholder is only for a film the
        // library has never seen.
        self::assertSame('WALL·E', $movies[0]->getTitle());
    }

    public function testAFavouriteTheLibraryHasNeverSeenBecomesAStub(): void
    {
        $this->write('2025-04-14,tom51200,,,,,,,,https://boxd.it/aaa');

        $touched = $this->importer()->import($this->csvPath, $this->batch());

        $movie = $this->entityManager->getRepository(Movie::class)->findOneBy(['letterboxdSlug' => 'film-aaa']);
        self::assertNotNull($movie);
        // Readable rather than right — TMDB enrichment replaces it, and the id is handed back
        // so that enrichment gets queued.
        self::assertSame('Film Aaa', $movie->getTitle());
        self::assertSame([(string) $movie->getId()], $touched);
    }

    public function testReimportingReplacesTheSlotsInsteadOfStackingThem(): void
    {
        $this->write('2025-04-14,tom51200,,,,,,,,"https://boxd.it/aaa, https://boxd.it/bbb"');
        $this->importer()->import($this->csvPath, $this->batch());

        // The favourites were rearranged on Letterboxd and one was dropped.
        $this->write('2025-04-14,tom51200,,,,,,,,https://boxd.it/bbb');
        $this->importer()->import($this->csvPath, $this->batch());

        $this->entityManager->clear();
        $profile = self::getContainer()->get(LetterboxdProfileRepository::class)->findOneByUser($this->user);

        self::assertNotNull($profile);
        self::assertCount(1, $profile->getFavourites(), 'the old slots must go, not accumulate');
        self::assertSame('film-bbb', $profile->getFavourites()->first()->getMovie()->getLetterboxdSlug());

        $rows = (int) $this->entityManager->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM letterboxd_profile')->fetchOne();
        self::assertSame(1, $rows, 'one profile per account, updated rather than added to');
    }

    public function testAProfileWithNoFavouritesIsStillAProfile(): void
    {
        $this->write('2025-04-14,tom51200,,,,France,,,,');

        $touched = $this->importer()->import($this->csvPath, $this->batch());

        $profile = self::getContainer()->get(LetterboxdProfileRepository::class)->findOneByUser($this->user);
        self::assertNotNull($profile);
        self::assertSame('France', $profile->getLocation());
        self::assertCount(0, $profile->getFavourites());
        self::assertSame([], $touched);
    }

    public function testALinkThatCannotBeResolvedCostsItsSlotAndNothingElse(): void
    {
        $this->write('2025-04-14,tom51200,,,,,,,,"https://boxd.it/aaa, https://boxd.it/zzz, https://boxd.it/bbb"');

        $batch = $this->batch();
        $this->importer()->import($this->csvPath, $batch);

        $profile = self::getContainer()->get(LetterboxdProfileRepository::class)->findOneByUser($this->user);
        self::assertNotNull($profile);

        // The two that resolved keep consecutive slots rather than leaving a hole at 2.
        self::assertSame(
            ['film-aaa', 'film-bbb'],
            array_map(static fn ($f) => $f->getMovie()->getLetterboxdSlug(), $profile->getFavourites()->toArray())
        );
        self::assertSame([1, 2], array_map(static fn ($f) => $f->getPosition(), $profile->getFavourites()->toArray()));

        // The row still counts as imported — the profile was read — but the loss is written
        // down where a reader would look for it.
        self::assertSame(1, $batch->getRowsImported());
        self::assertSame(0, $batch->getRowsFailed());
        self::assertCount(1, $batch->getRowErrors());
        self::assertStringContainsString('zzz', $batch->getRowErrors()->first()->getErrorMessage());
    }

    public function testOnlyFourSlotsAreEverFilled(): void
    {
        // Letterboxd offers four. If that ever changes, this block must not quietly turn
        // into a second watchlist.
        $this->write(
            '2025-04-14,tom51200,,,,,,,,'
            .'"https://boxd.it/aaa, https://boxd.it/bbb, https://boxd.it/ccc, https://boxd.it/ddd, https://boxd.it/eee"'
        );

        $this->importer()->import($this->csvPath, $this->batch());

        $profile = self::getContainer()->get(LetterboxdProfileRepository::class)->findOneByUser($this->user);
        self::assertNotNull($profile);
        self::assertCount(4, $profile->getFavourites());
    }

    /**
     * Every code but "zzz" points at a film; "zzz" is the one Letterboxd will not answer for.
     */
    private function importer(): ProfileImporter
    {
        $boxdIt = $this->createMock(BoxdItResolverInterface::class);
        $boxdIt->method('resolve')->willReturnCallback(
            static fn (string $code) => 'zzz' === $code ? null : "https://letterboxd.com/film/film-{$code}/"
        );

        return new ProfileImporter(
            self::getContainer()->get(CsvReader::class),
            new FilmSlugResolver($boxdIt, new LetterboxdSlugExtractor()),
            self::getContainer()->get(MovieUpserter::class),
            self::getContainer()->get(LetterboxdProfileRepository::class),
            $this->entityManager,
        );
    }

    private function batch(): ImportBatch
    {
        $batch = new ImportBatch($this->user, 'profile.csv', $this->csvPath, ImportFileType::PROFILE);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    private function write(string $row): void
    {
        file_put_contents(
            $this->csvPath,
            "Date Joined,Username,Given Name,Family Name,Email Address,Location,Website,Bio,Pronoun,Favorite Films\n"
            .$row."\n"
        );
    }
}
