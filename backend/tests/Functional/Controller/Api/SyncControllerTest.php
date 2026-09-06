<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The write half of the RSS sync settings, which did not exist.
 *
 * The username moved off the installation's configuration and onto the User row when the app
 * went multi-user, and stopped there: the migration seeded it once and nothing could change
 * it afterwards, so a second account had no way to sync at all. These tests are about the
 * seam that closes that — what the endpoint accepts, what it refuses, and whose row it writes.
 */
final class SyncControllerTest extends WebTestCase
{
    private const EMAIL = 'sync@example.com';
    private const PASSWORD = 'sync-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->createUser(self::EMAIL, 'Synchro');
        $this->login();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAccountCanPointItselfAtALetterboxdUsername(): void
    {
        $payload = $this->putSettings(['letterboxdUsername' => 'tom51200', 'rssSyncEnabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['configured']);
        self::assertSame('tom51200', $payload['username']);
        self::assertFalse($payload['autoSyncEnabled']);

        self::assertSame('tom51200', $this->reload(self::EMAIL)->getLetterboxdUsername());
    }

    public function testClearingTheUsernameIsHowSyncingIsSwitchedOff(): void
    {
        // There is no separate "disable" control, so an empty field has to mean something
        // rather than fail validation — otherwise you would need to invent a username to
        // stop using one.
        $this->putSettings(['letterboxdUsername' => 'tom51200', 'rssSyncEnabled' => true]);

        $payload = $this->putSettings(['letterboxdUsername' => '', 'rssSyncEnabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['configured']);
        self::assertNull($payload['username']);

        $reloaded = $this->reload(self::EMAIL);
        self::assertNull($reloaded->getLetterboxdUsername());
        self::assertFalse($reloaded->isRssSyncEnabled());
    }

    public function testAutomaticSyncingCannotBeTurnedOnWithoutAnAccountToSyncFrom(): void
    {
        // The scheduler's query already skips a user with no username, so nothing would have
        // broken — but the switch would have come back off with no explanation, which reads
        // as the save having failed.
        $this->putSettings(['letterboxdUsername' => '', 'rssSyncEnabled' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertFalse($this->reload(self::EMAIL)->isRssSyncEnabled());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedUsernames')]
    public function testAUsernameThatCouldRedirectTheFeedFetchIsRefused(string $username): void
    {
        // Load-bearing, not cosmetic: the value is interpolated into
        // https://letterboxd.com/{username}/rss/, so anything able to express a path
        // segment, a query string or a host aims the server's own fetch elsewhere.
        $this->putSettings(['letterboxdUsername' => $username, 'rssSyncEnabled' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertNull($this->reload(self::EMAIL)->getLetterboxdUsername());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedUsernames(): iterable
    {
        yield 'traversal' => ['../../evil'];
        yield 'path segment' => ['tom/rss'];
        yield 'query string' => ['tom?x=1'];
        yield 'absolute url' => ['https://evil.test'];
        yield 'too short' => ['t'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'space' => ['tom 51200'];
        yield 'dot' => ['tom.51200'];
    }

    public function testAHyphenatedUsernameIsAccepted(): void
    {
        // The allow-list guarantees an inert URL path segment; it is not a copy of
        // Letterboxd's signup rules, which cannot be observed from here. A hyphen is as
        // inert as an underscore, so refusing it would only risk locking out a real account.
        $payload = $this->putSettings(['letterboxdUsername' => 'jean-pierre', 'rssSyncEnabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame('jean-pierre', $payload['username']);
    }

    public function testSyncingCannotBeTriggeredWithoutAConfiguredAccount(): void
    {
        $this->client->request('POST', '/api/sync/letterboxd');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheSettingsBelongToTheAccountSigningIn(): void
    {
        // A profile shared with somebody stays read-only however they reached this screen,
        // so the write must land on the caller's row and nobody else's.
        $this->createUser('someone-else-sync@example.com', 'Quelquun');

        $this->putSettings(['letterboxdUsername' => 'tom51200', 'rssSyncEnabled' => false]);

        self::assertNull($this->reload('someone-else-sync@example.com')->getLetterboxdUsername());
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function putSettings(array $settings): array
    {
        $this->client->request(
            'PUT',
            '/api/sync/letterboxd',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($settings)
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Handling a request detaches whatever the test was holding, so refresh() throws — the
     * row has to be fetched again rather than re-read off a stale object.
     */
    private function reload(string $email): User
    {
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function createUser(string $email, string $displayName): User
    {
        $user = new User($email, $displayName);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD)
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function login(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD])
        );
        self::assertResponseIsSuccessful();
    }
}
