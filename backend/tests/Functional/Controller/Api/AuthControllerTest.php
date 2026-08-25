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
 * Covers the account lifecycle end to end over real HTTP: register, stay signed in, change
 * the password, and the rules that protect each step.
 */
final class AuthControllerTest extends WebTestCase
{
    private const EXISTING_EMAIL = 'existing@example.com';
    private const EXISTING_PASSWORD = 'existing-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Without this the kernel reboots between requests, which drops the open
        // transaction below along with the session cookie's server-side state.
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->createUser(self::EXISTING_EMAIL, self::EXISTING_PASSWORD);
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testRegisterCreatesTheAccountAndSignsItIn(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'new.user@example.com',
            'displayName' => 'New User',
            'password' => 'a-good-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('new.user@example.com', $this->json()['email']);

        // No second login call: registering must leave the browser authenticated.
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame('New User', $this->json()['displayName']);
    }

    public function testRegisterNormalisesTheEmailSoCasingCannotCreateTwoAccounts(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'MiXeD.Case@Example.COM',
            'displayName' => 'Mixed Case',
            'password' => 'a-good-password',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('mixed.case@example.com', $this->json()['email']);

        $this->postJson('/api/auth/register', [
            'email' => 'mixed.case@example.com',
            'displayName' => 'Impostor',
            'password' => 'a-good-password',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRegisterRejectsAnAlreadyUsedEmail(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => self::EXISTING_EMAIL,
            'displayName' => 'Impostor',
            'password' => 'a-good-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRegisterReportsAViolationPerInvalidField(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'not-an-email',
            'displayName' => 'x',
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $fields = array_column($this->json()['violations'], 'field');
        self::assertContains('email', $fields);
        self::assertContains('displayName', $fields);
        self::assertContains('password', $fields);
    }

    public function testChangingThePasswordSwapsTheCredentialsAndKeepsTheSession(): void
    {
        $this->login(self::EXISTING_EMAIL, self::EXISTING_PASSWORD);

        $this->putJson('/api/auth/password', [
            'currentPassword' => self::EXISTING_PASSWORD,
            'newPassword' => 'a-brand-new-password',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Proving you know the old password shouldn't log you out of the tab you're in.
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $this->postJson('/api/auth/login', ['email' => self::EXISTING_EMAIL, 'password' => self::EXISTING_PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'The old password must stop working.');

        $this->postJson('/api/auth/login', ['email' => self::EXISTING_EMAIL, 'password' => 'a-brand-new-password']);
        self::assertResponseIsSuccessful();
    }

    public function testChangingThePasswordRequiresTheCurrentOne(): void
    {
        $this->login(self::EXISTING_EMAIL, self::EXISTING_PASSWORD);

        $this->putJson('/api/auth/password', [
            'currentPassword' => 'not-the-current-one',
            'newPassword' => 'a-brand-new-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // And the real one must still work, i.e. nothing was written.
        $this->postJson('/api/auth/login', ['email' => self::EXISTING_EMAIL, 'password' => self::EXISTING_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    public function testChangingThePasswordRequiresAuthentication(): void
    {
        $this->putJson('/api/auth/password', [
            'currentPassword' => self::EXISTING_PASSWORD,
            'newPassword' => 'a-brand-new-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function login(string $email, string $password): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
    }

    private function createUser(string $email, string $plainPassword): User
    {
        $user = new User($email, $email);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->requestJson('POST', $uri, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putJson(string $uri, array $payload): void
    {
        $this->requestJson('PUT', $uri, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestJson(string $method, string $uri, array $payload): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
