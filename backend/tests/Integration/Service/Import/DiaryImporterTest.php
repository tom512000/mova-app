<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Repository\MovieRepository;
use App\Service\Import\Importers\DiaryImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the real idempotency contract described in the plan: importing the
 * same diary.csv twice must never duplicate a Movie or a Watch, rewatches must
 * create a second Watch (not overwrite the first), and one malformed row must
 * not abort the rest of the batch.
 */
final class DiaryImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $csvPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $csv = <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date
            2024-03-13,Interstellar,2014,https://letterboxd.com/johndoe/film/interstellar/,4.5,,sci-fi,2024-03-12
            2026-07-26,Interstellar,2014,https://letterboxd.com/johndoe/film/interstellar/2/,5,Yes,,2026-07-25
            2024-01-01,,2020,https://letterboxd.com/johndoe/film/missing-name/,3,,,2024-01-01
            CSV;

        $this->csvPath = tempnam(sys_get_temp_dir(), 'diary').'.csv';
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

    public function testImportHandlesRewatchesAndIsolatesBadRows(): void
    {
        $importer = self::getContainer()->get(DiaryImporter::class);
        $batch = $this->createBatch();

        $importer->import($this->csvPath, $batch);

        self::assertSame(2, $batch->getRowsImported());
        self::assertSame(1, $batch->getRowsFailed());
        self::assertCount(1, $batch->getRowErrors());

        $movie = $this->movieRepository()->findOneByLetterboxdSlug('interstellar');
        self::assertNotNull($movie);
        self::assertCount(2, $movie->getWatches(), 'A rewatch must create a second Watch, not overwrite the first.');

        self::assertNull(
            $this->movieRepository()->findOneByLetterboxdSlug('missing-name'),
            'A row that fails validation before the Movie upsert must not create an orphan Movie.'
        );
    }

    public function testReimportingTheSameFileNeverDuplicates(): void
    {
        $importer = self::getContainer()->get(DiaryImporter::class);

        $importer->import($this->csvPath, $this->createBatch());
        $importer->import($this->csvPath, $this->createBatch());

        $this->entityManager->clear();

        $movie = $this->movieRepository()->findOneByLetterboxdSlug('interstellar');
        self::assertNotNull($movie);
        self::assertCount(2, $movie->getWatches(), 'Re-importing the same diary.csv must not duplicate Watch rows.');
    }

    private function createBatch(): ImportBatch
    {
        $batch = new ImportBatch('diary.csv', $this->csvPath, ImportFileType::DIARY);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    private function movieRepository(): MovieRepository
    {
        return self::getContainer()->get(MovieRepository::class);
    }
}
