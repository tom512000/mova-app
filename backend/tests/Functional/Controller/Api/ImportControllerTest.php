<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\ImportBatch;
use App\Entity\User;
use App\Message\ProcessImportBatchMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The upload round trip, end to end through HTTP.
 *
 * This file exists because its absence cost something: when every primary key became a
 * UUID, three ids in this path stayed typed `int` and nothing noticed. The importers had
 * their own tests and passed; the orchestrator had none; the controller had none. So the
 * assertions below are deliberately about the *seams* — what the route accepts, what the
 * envelope carries, what comes back out — rather than about parsing a CSV, which is
 * already covered a layer down.
 */
final class ImportControllerTest extends WebTestCase
{
    private const EMAIL = 'importer@example.com';
    private const PASSWORD = 'importer-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private string $zipPath;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User(self::EMAIL, 'Importer');
        $this->user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::PASSWORD)
        );
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        $this->zipPath = $this->buildExportZip();
        $this->login();
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUploadingAnExportCreatesABatchPerRecognisedFile(): void
    {
        $payload = $this->upload();

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        // comments.csv is a real file in a Letterboxd export that nothing here reads, so it
        // comes back named rather than silently dropped. The .txt never appears at all —
        // only CSVs are pulled out of the archive in the first place.
        self::assertSame(['comments.csv'], $payload['unsupportedFiles']);

        $filenames = array_column($payload['batches'], 'filename');
        sort($filenames);
        self::assertSame(['diary.csv', 'profile.csv', 'ratings.csv'], $filenames);

        foreach ($payload['batches'] as $batch) {
            // A UUID and not a number — the shape the whole chain below has to agree on.
            self::assertTrue(Uuid::isValid((string) $batch['id']), 'a batch id must be a UUID');
        }
    }

    public function testTheQueuedWorkOrderCarriesTheBatchIdAsAString(): void
    {
        $this->upload();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = array_map(
            static fn ($envelope) => $envelope->getMessage(),
            $transport->getSent()
        );
        self::assertCount(3, $messages);

        foreach ($messages as $message) {
            self::assertInstanceOf(ProcessImportBatchMessage::class, $message);
            // The envelope is serialised into a queue and read back by a separate process,
            // so what travels has to be a scalar the worker can still resolve.
            self::assertTrue(Uuid::isValid($message->importBatchId));
        }
    }

    public function testTheDiaryIsQueuedBeforeTheRatings(): void
    {
        $this->upload();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $order = array_map(
            fn ($envelope) => $this->batch($envelope->getMessage()->importBatchId)->getFilename(),
            $transport->getSent()
        );

        // A single sequential worker consumes these in order, and RatingsImporter's backfill
        // depends on the diary already being in. profile.csv goes last so its favourites find
        // films that already carry a title. The sort in the controller is the only thing
        // holding either, so it is worth pinning.
        self::assertSame(['diary.csv', 'ratings.csv', 'profile.csv'], $order);
    }

    public function testABatchCanBeFollowedByItsId(): void
    {
        $payload = $this->upload();
        $id = (string) $payload['batches'][0]['id'];

        $this->client->request('GET', "/api/import/{$id}");

        self::assertResponseIsSuccessful();
        self::assertSame($id, (string) $this->json()['id']);
    }

    public function testSomebodyElsesImportIsNotFound(): void
    {
        $payload = $this->upload();
        $id = (string) $payload['batches'][0]['id'];

        $stranger = new User('stranger@example.com', 'Stranger');
        $stranger->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($stranger, 'stranger-password')
        );
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        $this->login('stranger@example.com', 'stranger-password');
        $this->client->request('GET', "/api/import/{$id}");

        // 404 rather than 403: the existence of another account's import is not something
        // this endpoint should confirm either.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheHistoryListsWhatWasUploaded(): void
    {
        $this->upload();

        $this->client->request('GET', '/api/import');

        self::assertResponseIsSuccessful();
        $filenames = array_column($this->json(), 'filename');
        sort($filenames);
        self::assertSame(['diary.csv', 'profile.csv', 'ratings.csv'], $filenames);
    }

    public function testAnUnreadableUploadIsRefusedRatherThanStored(): void
    {
        $this->client->request(
            'POST',
            '/api/import/letterboxd',
            files: ['file' => new UploadedFile(__FILE__, 'notes.txt', 'text/plain', test: true)]
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(): array
    {
        $this->client->request(
            'POST',
            '/api/import/letterboxd',
            files: ['file' => new UploadedFile($this->zipPath, 'letterboxd-export.zip', 'application/zip', test: true)]
        );

        return $this->json();
    }

    private function batch(string $id): ImportBatch
    {
        $batch = $this->entityManager->find(ImportBatch::class, $id);
        self::assertNotNull($batch);

        return $batch;
    }

    /**
     * A miniature export: two files the registry knows, one it does not, and one in a
     * subfolder — Letterboxd really does ship deleted/diary.csv, and it must not be taken
     * for the real one.
     */
    private function buildExportZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.zip';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'diary.csv',
            "Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date\n2024-03-13,Interstellar,2014,https://letterboxd.com/johndoe/film/interstellar/,4.5,,,2024-03-12\n"
        );
        $zip->addFromString(
            'ratings.csv',
            "Date,Name,Year,Letterboxd URI,Rating\n2024-03-13,Arrival,2016,https://letterboxd.com/johndoe/film/arrival/,4\n"
        );
        $zip->addFromString(
            'profile.csv',
            "Date Joined,Username,Given Name,Family Name,Email Address,Location,Website,Bio,Pronoun,Favorite Films
"
            ."2025-04-14,tom51200,,,tom@example.com,France,,,He / his,
"
        );
        $zip->addFromString('comments.csv', "Date,Comment\n2024-01-01,rien a voir\n");
        $zip->addFromString('notes-perso.txt', "rien a voir non plus\n");
        $zip->addFromString(
            'deleted/diary.csv',
            "Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date\n2020-01-01,Supprime,1999,https://letterboxd.com/johndoe/film/supprime/,1,,,2020-01-01\n"
        );
        $zip->close();

        return $path;
    }

    private function login(string $email = self::EMAIL, string $password = self::PASSWORD): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password])
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
