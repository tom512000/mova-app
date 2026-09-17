<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The shelf over real HTTP. The rules themselves are pinned by TrophyCalculatorTest; what is
 * left for this file is which days reach them at all — whose, and which kind of row.
 */
final class TrophyControllerTest extends WebTestCase
{
    private const EMAIL = 'trophies@example.com';
    private const PASSWORD = 'trophies-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $user = $this->account(self::EMAIL);
        $stranger = $this->account('trophies-stranger@example.com');

        // A real Christmas evening.
        $this->watch($user, '2024-12-25', WatchSource::MANUAL);
        // A note revised on Valentine's Day, without the film being watched again.
        $this->watch($user, '2025-02-14', WatchSource::CSV_RERATING);
        // Somebody else's Halloween.
        $this->watch($stranger, '2025-10-31', WatchSource::MANUAL);
        // A viewing with no date says nothing about any day.
        $this->watch($user, null, WatchSource::MANUAL);

        $this->entityManager->flush();
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

    public function testEveryTrophyIsListedWonOrNot(): void
    {
        $trophies = $this->shelf();

        self::assertCount(14, $trophies);
        self::assertSame('groundhog_day', $trophies[0]['key']);
        self::assertSame('regularity', $trophies[0]['family']);
        self::assertSame([3, 7, 14, 30, 60, 100], $trophies[0]['tiers']);
    }

    public function testARealEveningWinsItsTrophyAndIsDatedToIt(): void
    {
        $christmas = $this->byKey()['christmas'];

        self::assertSame(1, $christmas['level']);
        self::assertSame('2024-12-25', $christmas['earnedOn']);
    }

    public function testARevisedRatingIsNotAnEvening(): void
    {
        // Counted, it would hand out "Seul au monde" for moving a note on the 14th.
        $valentine = $this->byKey()['valentine'];

        self::assertSame(0, $valentine['level']);
        self::assertNull($valentine['earnedOn']);
    }

    public function testAnotherAccountsEveningsAreNotMine(): void
    {
        self::assertSame(0, $this->byKey()['halloween']['level']);
    }

    /**
     * The shelf as the API returns it, in catalogue order.
     *
     * @return list<array<string, mixed>>
     */
    private function shelf(): array
    {
        $this->client->request('GET', '/api/trophies');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byKey(): array
    {
        return array_column($this->shelf(), null, 'key');
    }

    private function account(string $email): User
    {
        $user = new User($email, 'Trophies');
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD)
        );
        $this->entityManager->persist($user);

        return $user;
    }

    private function watch(User $user, ?string $date, WatchSource $source): void
    {
        $movie = new Movie('test-trophy-'.++$this->counter, 'ZZ Trophee '.$this->counter);
        $this->entityManager->persist($movie);

        $watch = new Watch($user, $movie, $source);
        if (null !== $date) {
            $watch->setWatchedDate(new \DateTimeImmutable($date));
        }
        $watch->setRating(3.5);
        $this->entityManager->persist($watch);
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
