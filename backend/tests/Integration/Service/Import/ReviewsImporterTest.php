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
use App\Service\Import\Importers\ReviewsImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * reviews.csv repeats diary.csv's columns and adds "Review", so the thing worth pinning
 * here is that it lands the text on the diary entry it belongs to instead of creating a
 * second viewing of the same film — and that it leaves the rest of that entry alone.
 */
final class ReviewsImporterTest extends KernelTestCase
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

        $this->user = new User('reviewer@example.com', 'Reviewer');
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

    public function testTheReviewLandsOnTheDiaryEntryItDescribes(): void
    {
        $this->importDiary();
        $this->importReviews();

        $movie = $this->movie('neuilly-sa-mere-sa-mere');
        self::assertCount(1, $movie->getWatches(), 'the review describes that viewing, it is not another one');

        $watch = $movie->getWatches()->first();
        self::assertInstanceOf(Watch::class, $watch);
        self::assertSame('Ca put sa mère, sa mère !', $watch->getReviewText());
    }

    public function testItLeavesTheRestOfTheDiaryEntryAlone(): void
    {
        $this->importDiary();

        // A reviews.csv older than the diary.csv beside it: same entry, stale rating.
        $this->importReviews(<<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Review,Tags,Watched Date
            2026-08-18,"Neuilly sa mère, sa mère !",2018,https://letterboxd.com/tom/film/neuilly-sa-mere-sa-mere/,0.5,Yes,"Ca put sa mère, sa mère !",,2026-08-18
            CSV);

        $watch = $this->movie('neuilly-sa-mere-sa-mere')->getWatches()->first();
        self::assertInstanceOf(Watch::class, $watch);
        self::assertSame(2.5, $watch->getRating(), 'the rating is diary.csv\'s to own');
        self::assertFalse($watch->isRewatch());
        self::assertNotNull($watch->getReviewText());
    }

    public function testReimportingTheSameFileNeverDuplicates(): void
    {
        $this->importDiary();
        $this->importReviews();
        $this->importReviews();

        $this->entityManager->clear();

        self::assertCount(1, $this->movie('neuilly-sa-mere-sa-mere')->getWatches());
    }

    public function testAReviewWithoutItsDiaryFileStillFindsItsWayHome(): void
    {
        // reviews.csv uploaded on its own: the viewing has to be created, under the very
        // ref diary.csv would have used…
        $this->importReviews();

        $watch = $this->movie('neuilly-sa-mere-sa-mere')->getWatches()->first();
        self::assertInstanceOf(Watch::class, $watch);
        self::assertSame('Ca put sa mère, sa mère !', $watch->getReviewText());
        self::assertSame(2.5, $watch->getRating(), 'with nothing else to go on, it takes its own row at its word');

        // …so that diary.csv arriving later completes that row instead of doubling it.
        $this->importDiary();
        $this->entityManager->clear();

        $movie = $this->movie('neuilly-sa-mere-sa-mere');
        self::assertCount(1, $movie->getWatches());
        self::assertNotNull($movie->getWatches()->first()->getReviewText(), 'and without losing the review');
    }

    public function testAViewingKnownOnlyFromRatingsStillTakesTheReview(): void
    {
        // No diary entry, so no matching ref — but a viewing of that film on that day is
        // unambiguously the one being reviewed.
        $existing = new Watch($this->user, $this->upsertedMovie(), WatchSource::CSV_IMPORT);
        $existing->setWatchedDate(new \DateTimeImmutable('2026-08-18'));
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        // A different URI for the same film — the "2" is the nth diary entry, not identity.
        $this->importReviews(<<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Review,Tags,Watched Date
            2026-08-18,"Neuilly sa mère, sa mère !",2018,https://letterboxd.com/tom/film/neuilly-sa-mere-sa-mere/2/,2.5,,"Ca put sa mère, sa mère !",,2026-08-18
            CSV);

        $this->entityManager->clear();

        $movie = $this->movie('neuilly-sa-mere-sa-mere');
        self::assertCount(1, $movie->getWatches(), 'the review joins that viewing rather than inventing one');
        self::assertSame('Ca put sa mère, sa mère !', $movie->getWatches()->first()->getReviewText());
    }

    public function testARowWithNothingWrittenIsSkippedRatherThanFailed(): void
    {
        $batch = $this->importReviews(<<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Review,Tags,Watched Date
            2026-08-18,"Neuilly sa mère, sa mère !",2018,https://letterboxd.com/tom/film/neuilly-sa-mere-sa-mere/,2.5,,,,2026-08-18
            CSV);

        self::assertSame(0, $batch->getRowsImported());
        self::assertSame(1, $batch->getRowsSkipped());
        self::assertSame(0, $batch->getRowsFailed());
    }

    public function testItClaimsReviewsCsvAndNothingElse(): void
    {
        $importer = self::getContainer()->get(ReviewsImporter::class);
        $header = ['Date', 'Name', 'Year', 'Letterboxd URI', 'Rating', 'Rewatch', 'Review', 'Tags', 'Watched Date'];

        self::assertTrue($importer->supports('reviews.csv', $header));
        // The two files differ by one column, so only the name can tell them apart.
        self::assertFalse($importer->supports('diary.csv', $header));
    }

    private function importDiary(): ImportBatch
    {
        $batch = $this->batch('diary.csv', ImportFileType::DIARY, <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date
            2026-08-18,"Neuilly sa mère, sa mère !",2018,https://letterboxd.com/tom/film/neuilly-sa-mere-sa-mere/,2.5,,,2026-08-18
            CSV);

        self::getContainer()->get(DiaryImporter::class)->import($batch->getStoredPath(), $batch);

        return $batch;
    }

    private function importReviews(?string $csv = null): ImportBatch
    {
        // The real export, verbatim: the URI is the diary entry's, the same one diary.csv
        // carries for it, which is the whole reason the join can be exact.
        $batch = $this->batch('reviews.csv', ImportFileType::REVIEWS, $csv ?? <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Review,Tags,Watched Date
            2026-08-18,"Neuilly sa mère, sa mère !",2018,https://letterboxd.com/tom/film/neuilly-sa-mere-sa-mere/,2.5,,"Ca put sa mère, sa mère !",,2026-08-18
            CSV);

        self::getContainer()->get(ReviewsImporter::class)->import($batch->getStoredPath(), $batch);

        return $batch;
    }

    private function batch(string $filename, ImportFileType $type, string $csv): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'reviews').'.csv';
        file_put_contents($path, $csv);
        $this->paths[] = $path;

        $batch = new ImportBatch($this->user, $filename, $path, $type);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    private function upsertedMovie(): Movie
    {
        $movie = new Movie('neuilly-sa-mere-sa-mere', 'Neuilly sa mère, sa mère !');
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function movie(string $slug): Movie
    {
        $movie = self::getContainer()->get(MovieRepository::class)->findOneByLetterboxdSlug($slug);
        self::assertNotNull($movie);

        return $movie;
    }
}
