<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\ImportStatus;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Import\ImportOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What happens when the *same* batch is processed twice.
 *
 * DiaryImporterTest already covers re-importing the same file, but it hands each pass a
 * fresh batch — which is not what a retry looks like. A redelivered Messenger envelope
 * carries the id of a batch that has already run, so the second pass finds the counters of
 * the first still sitting in the row. That is how a 721-line ratings.csv came to report
 * 4320 imported lines: five redeliveries, each adding its own 720 to the tally.
 */
final class ImportOrchestratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $csvPath;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('orchestrator@example.com', 'Orchestrator');
        $this->user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        // Two good rows and one with no title, so every tally has something to count.
        $csv = <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date
            2024-03-13,Interstellar,2014,https://letterboxd.com/johndoe/film/interstellar/,4.5,,,2024-03-12
            2024-04-02,Arrival,2016,https://letterboxd.com/johndoe/film/arrival/,4,,,2024-04-01
            2024-01-01,,2020,https://letterboxd.com/johndoe/film/missing-name/,3,,,2024-01-01
            CSV;

        $this->csvPath = tempnam(sys_get_temp_dir(), 'orchestrator').'.csv';
        file_put_contents($this->csvPath, $csv);
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

    public function testAReprocessedBatchReportsOneRunRatherThanTheSumOfThemAll(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $batch = $this->createBatch();

        $orchestrator->process($batch);

        $imported = $batch->getRowsImported();
        $failed = $batch->getRowsFailed();
        self::assertSame(2, $imported);
        self::assertSame(1, $failed);

        // The retry: the very same row, re-read from the top.
        $orchestrator->process($batch);

        self::assertSame($imported, $batch->getRowsImported(), 'a second pass must not add to the first');
        self::assertSame($failed, $batch->getRowsFailed());
        self::assertSame(0, $batch->getRowsSkipped());
        self::assertSame(3, $batch->getRowsTotal(), 'the file is still three lines long');
    }

    public function testProcessingTwiceStillImportsTheRowsOnlyOnce(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $batch = $this->createBatch();

        $orchestrator->process($batch);
        $orchestrator->process($batch);

        $this->entityManager->clear();

        // The tallies being right would mean little if the data underneath them were not:
        // the counters describe one pass because the second pass genuinely changed nothing.
        self::assertCount(2, $this->entityManager->getRepository(Watch::class)->findBy(['user' => $this->user]));
    }

    public function testABatchThatFailedOnceCanStillCompleteOnTheRetry(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $batch = $this->createBatch();

        // The file is missing on the first attempt — the shape of a genuine transient
        // failure, and the state a retry has to be able to climb out of.
        rename($this->csvPath, $this->csvPath.'.away');
        $orchestrator->process($batch);
        self::assertSame(ImportStatus::FAILED, $batch->getStatus());

        rename($this->csvPath.'.away', $this->csvPath);
        $orchestrator->process($batch);

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $batch->getStatus());
        self::assertSame(2, $batch->getRowsImported());
    }

    public function testEveryFilmTheImportTouchedIsQueuedTheFirstTimeRound(): void
    {
        $this->transport()->reset();
        self::getContainer()->get(ImportOrchestrator::class)->process($this->createBatch());

        // Two rows carry a usable film; the third has no title and never gets that far.
        self::assertCount(2, $this->transport()->getSent());
    }

    public function testAFilmTheLibraryAlreadyKnowsEverythingAboutIsNotQueuedAgain(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $orchestrator->process($this->createBatch());

        $this->setStatusOfEveryFilm(EnrichmentStatus::ENRICHED);

        // The same export imported again — by this account or, more to the point, by another
        // one, which is where nothing gets skipped along the way and every row comes back.
        $this->transport()->reset();
        $orchestrator->process($this->createBatch());

        self::assertSame(
            [],
            $this->transport()->getSent(),
            'an enriched film has nothing to ask TMDB, so it should not cost a message either'
        );
    }

    public function testAFilmDeliberatelyExcludedIsNotQueuedEither(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $orchestrator->process($this->createBatch());

        // EXCLUDED is a human decision that this entry has no TMDB match. Re-queueing it is
        // what used to send TmdbResolver hunting for a wrong one on every re-import.
        $this->setStatusOfEveryFilm(EnrichmentStatus::EXCLUDED);

        $this->transport()->reset();
        $orchestrator->process($this->createBatch());

        self::assertSame([], $this->transport()->getSent());
    }

    public function testAFilmWhoseEnrichmentFailedIsQueuedAgain(): void
    {
        $orchestrator = self::getContainer()->get(ImportOrchestrator::class);
        $orchestrator->process($this->createBatch());

        // The other half of the rule: filtering must not turn into never retrying. FAILED
        // and AMBIGUOUS are both still worth an attempt.
        $this->setStatusOfEveryFilm(EnrichmentStatus::FAILED);

        $this->transport()->reset();
        $orchestrator->process($this->createBatch());

        self::assertCount(2, $this->transport()->getSent());
    }

    private function setStatusOfEveryFilm(EnrichmentStatus $status): void
    {
        foreach ($this->entityManager->getRepository(Movie::class)->findAll() as $movie) {
            $movie->setEnrichmentStatus($status);
        }
        $this->entityManager->flush();
    }

    private function transport(): InMemoryTransport
    {
        // messenger.yaml routes async to in-memory under when@test, so what the orchestrator
        // dispatched can be read back without a worker.
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createBatch(): ImportBatch
    {
        $batch = new ImportBatch($this->user, 'diary.csv', $this->csvPath, ImportFileType::DIARY);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }
}
