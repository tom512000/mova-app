<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\ImportStatus;
use App\Entity\ImportBatch;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Import\ImportOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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

    private function createBatch(): ImportBatch
    {
        $batch = new ImportBatch($this->user, 'diary.csv', $this->csvPath, ImportFileType::DIARY);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }
}
