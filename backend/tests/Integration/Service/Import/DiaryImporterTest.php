<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\MovieRepository;
use App\Service\Import\Importers\DiaryImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the real idempotency contract described in the plan: importing the
 * same diary.csv twice must never duplicate a Movie or a Watch, rewatches must
 * create a second Watch (not overwrite the first), and one malformed row must
 * not abort the rest of the batch.
 *
 * Since imports became per-account, it also pins the isolation rule: the same export
 * loaded by two users produces two independent sets of Watch rows over one shared Movie.
 */
final class DiaryImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $csvPath;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('importer@example.com');

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
        $batch = $this->createBatch($this->user);

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

        $importer->import($this->csvPath, $this->createBatch($this->user));
        $importer->import($this->csvPath, $this->createBatch($this->user));

        $this->entityManager->clear();

        $movie = $this->movieRepository()->findOneByLetterboxdSlug('interstellar');
        self::assertNotNull($movie);
        self::assertCount(2, $movie->getWatches(), 'Re-importing the same diary.csv must not duplicate Watch rows.');
    }

    public function testTwoUsersImportingTheSameExportKeepSeparateWatches(): void
    {
        $importer = self::getContainer()->get(DiaryImporter::class);
        $other = $this->createUser('other@example.com');

        $importer->import($this->csvPath, $this->createBatch($this->user));
        // The second user's rows share the same externalRef values as the first user's.
        // Only the composite (user_id, external_ref) uniqueness makes this legal, and only
        // the user-scoped lookup in DiaryImporter stops it being seen as "already imported".
        $importer->import($this->csvPath, $this->createBatch($other));

        $this->entityManager->clear();

        $movie = $this->movieRepository()->findOneByLetterboxdSlug('interstellar');
        self::assertNotNull($movie);
        self::assertCount(4, $movie->getWatches(), 'Each account keeps its own Watch rows over the one shared Movie.');

        $watchRepository = $this->entityManager->getRepository(Watch::class);
        self::assertCount(2, $watchRepository->findBy(['user' => $this->user]));
        self::assertCount(2, $watchRepository->findBy(['user' => $other]));
    }

    private function createUser(string $email): User
    {
        $user = new User($email, $email);
        $user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createBatch(User $user): ImportBatch
    {
        $batch = new ImportBatch($user, 'diary.csv', $this->csvPath, ImportFileType::DIARY);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    private function movieRepository(): MovieRepository
    {
        return self::getContainer()->get(MovieRepository::class);
    }
}
