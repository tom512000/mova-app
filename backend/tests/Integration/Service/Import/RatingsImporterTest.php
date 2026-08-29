<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\MovieRepository;
use App\Service\Import\Importers\DiaryImporter;
use App\Service\Import\Importers\RatingsImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ratings.csv holds one row per film and rewrites it in place, so the only thing that
 * distinguishes "the same viewing, exported again" from "a second opinion" is whether the
 * Date has moved. Everything below is about telling those two apart.
 *
 * This matters because the importer used to make no distinction at all: it overwrote the
 * rating and the date, and an earlier opinion was destroyed on every import. Observed on two
 * consecutive real exports — L'Arnacœur went from 3.5 on the 21st to 4 on the 25th, and the
 * 3.5 survived nowhere. The scenarios below are that story, and the ways it can go wrong.
 */
final class RatingsImporterTest extends KernelTestCase
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

        $this->user = new User('ratings@example.com', 'Ratings');
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

    public function testAFilmSeenForTheFirstTimeIsRecordedOnce(): void
    {
        $this->importRatings('2026-08-21', '3.5');

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(3.5, $watches[0]->getRating());
        self::assertSame('2026-08-21', $watches[0]->getWatchedDate()?->format('Y-m-d'));
        self::assertFalse($watches[0]->isRewatch(), 'a first viewing is not a rewatch');
        self::assertSame(WatchSource::CSV_IMPORT, $watches[0]->getSource());
    }

    public function testTheSameExportImportedAgainChangesNothing(): void
    {
        $this->importRatings('2026-08-21', '3.5');
        $this->importRatings('2026-08-21', '3.5');

        self::assertCount(1, $this->watches(), 'the same row is the same viewing, however often it arrives');
    }

    public function testADateThatHasMovedRecordsASecondViewingInsteadOfOverwritingTheFirst(): void
    {
        // The real scenario, verbatim.
        $this->importRatings('2026-08-21', '3.5');
        $this->importRatings('2026-08-25', '4');

        $watches = $this->watches();
        self::assertCount(2, $watches);

        // The first opinion is the whole point: it used to be destroyed here.
        self::assertSame(3.5, $watches[0]->getRating());
        self::assertSame('2026-08-21', $watches[0]->getWatchedDate()?->format('Y-m-d'));
        self::assertFalse($watches[0]->isRewatch());

        self::assertSame(4.0, $watches[1]->getRating());
        self::assertSame('2026-08-25', $watches[1]->getWatchedDate()?->format('Y-m-d'));
        self::assertTrue($watches[1]->isRewatch());
        self::assertSame(WatchSource::CSV_RERATING, $watches[1]->getSource());
        self::assertTrue($watches[1]->getSource()->isDeduced(), 'nobody declared this one');
    }

    public function testARewatchThatDidNotChangeTheRatingIsStillASecondViewing(): void
    {
        // Watching a film again and standing by the note moves the date and nothing else.
        $this->importRatings('2026-08-21', '4');
        $this->importRatings('2026-08-25', '4');

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(4.0, $watches[1]->getRating());
        self::assertTrue($watches[1]->isRewatch());
    }

    public function testAThirdOpinionStacksOnTopOfTheOtherTwo(): void
    {
        $this->importRatings('2026-08-21', '3.5');
        $this->importRatings('2026-08-25', '4');
        $this->importRatings('2026-09-02', '2.5');

        self::assertSame(
            [3.5, 4.0, 2.5],
            array_map(static fn (Watch $w) => $w->getRating(), $this->watches())
        );
    }

    public function testAnOlderExportLoadedAfterANewerOneDoesNotRewindTheLibrary(): void
    {
        $this->importRatings('2026-08-25', '4');

        // The export from before the re-rating, uploaded second. Recording it would invent a
        // viewing in the past, and overwriting with it would undo the one already known.
        $batch = $this->importRatings('2026-08-21', '3.5');

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(4.0, $watches[0]->getRating());
        self::assertSame('2026-08-25', $watches[0]->getWatchedDate()?->format('Y-m-d'));
        self::assertSame(1, $batch->getRowsSkipped());
        self::assertSame(0, $batch->getRowsImported());
    }

    public function testARatingChangedWithoutTheDateMovingIsACorrectionRatherThanAViewing(): void
    {
        // Letterboxd moves the Date whenever the rating changes, so this should not occur —
        // but a hand-edited file must not be able to invent a second viewing out of it.
        $this->importRatings('2026-08-21', '3.5');
        $this->importRatings('2026-08-21', '5');

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(5.0, $watches[0]->getRating());
    }

    public function testADiaryEntryIsLeftToDiaryCsv(): void
    {
        $this->importDiary('2026-08-21', '2.5');

        // Same film, same day, a different note in ratings.csv. diary.csv owns the entry.
        $batch = $this->importRatings('2026-08-21', '5');

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(2.5, $watches[0]->getRating(), "the diary's rating stands");
        self::assertSame(1, $batch->getRowsSkipped());
    }

    public function testARatingLoggedAfterADiaryEntryIsASecondViewing(): void
    {
        $this->importDiary('2026-08-21', '2.5');
        $this->importRatings('2026-08-25', '4');

        $watches = $this->watches();
        self::assertCount(2, $watches, 'the diary entry stays, the later opinion joins it');
        self::assertSame(2.5, $watches[0]->getRating());
        self::assertSame(4.0, $watches[1]->getRating());
        self::assertTrue($watches[1]->isRewatch());
    }

    public function testARowWithNoDateCannotBeToldApartFromTheLastOne(): void
    {
        // Nothing to compare, so nothing is invented: the rating lands on the viewing that
        // is already there rather than creating a dateless second one beside it.
        $this->importRatings('2026-08-21', '3.5');
        $this->importRatings('', '4');

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(4.0, $watches[0]->getRating());
        self::assertSame('2026-08-21', $watches[0]->getWatchedDate()?->format('Y-m-d'), 'the known date is kept');
    }

    /**
     * @return list<Watch>
     */
    private function watches(): array
    {
        $this->entityManager->clear();

        $movie = self::getContainer()->get(MovieRepository::class)->findOneByLetterboxdSlug('lheartbreaker');
        self::assertInstanceOf(Movie::class, $movie);

        $watches = array_values(array_filter(
            $movie->getWatches()->toArray(),
            fn (Watch $w) => $w->getUser()->getId()->equals($this->user->getId())
        ));
        usort(
            $watches,
            static fn (Watch $a, Watch $b) => ($a->getWatchedDate()?->format('Y-m-d') ?? '') <=> ($b->getWatchedDate()?->format('Y-m-d') ?? '')
        );

        return $watches;
    }

    private function importRatings(string $date, string $rating): ImportBatch
    {
        $batch = $this->batch('ratings.csv', ImportFileType::RATINGS, <<<CSV
            Date,Name,Year,Letterboxd URI,Rating
            {$date},L'Arnacœur,2010,https://letterboxd.com/tom/film/lheartbreaker/,{$rating}
            CSV);

        self::getContainer()->get(RatingsImporter::class)->import($batch->getStoredPath(), $batch);

        return $batch;
    }

    private function importDiary(string $date, string $rating): ImportBatch
    {
        $batch = $this->batch('diary.csv', ImportFileType::DIARY, <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date
            {$date},L'Arnacœur,2010,https://letterboxd.com/tom/film/lheartbreaker/,{$rating},,,{$date}
            CSV);

        self::getContainer()->get(DiaryImporter::class)->import($batch->getStoredPath(), $batch);

        return $batch;
    }

    private function batch(string $filename, ImportFileType $type, string $csv): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'ratings').'.csv';
        file_put_contents($path, $csv);
        $this->paths[] = $path;

        $batch = new ImportBatch($this->user, $filename, $path, $type);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }
}
