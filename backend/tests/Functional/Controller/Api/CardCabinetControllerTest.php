<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\ProfileAccess;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Card\CardCatalogueBuilder;
use App\Service\Card\CardPackOpener;
use App\Service\Card\JetonTariff;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Cabinet over HTTP, and mostly: whose Cabinet.
 *
 * Collecting is a write, and the rule this codebase keeps everywhere is that writes belong
 * to the account making them. A `?profileId=` forged onto a pack opening has to draw from
 * the caller's own catalogue and spend the caller's own jetons — not refuse, not error,
 * simply ignore the parameter. Most of this file is that one sentence, checked from several
 * directions, because the failure mode is silent: it would work perfectly and quietly spend
 * somebody else's money.
 *
 * The other half is the daily grant, whose whole design is one conditional UPDATE, and the
 * only way to know it is idempotent is to ask for it twice.
 */
final class CardCabinetControllerTest extends WebTestCase
{
    private const PASSWORD = 'cabinet-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $owner;
    private User $viewer;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->owner = $this->account('zz-owner@example.com');
        $this->viewer = $this->account('zz-viewer@example.com');
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------- daily grant

    public function testTheConnectionGrantIsPaidOnceAParisDayHoweverOftenItIsAskedFor(): void
    {
        $this->login('zz-owner@example.com');

        $first = $this->post('/api/cards/cabinet/daily');
        self::assertTrue($first['granted']);
        self::assertSame(JetonTariff::DAILY_BASE, $first['amount']);
        self::assertSame(JetonTariff::DAILY_BASE, $first['balance']);
        self::assertSame(1, $first['streakDays']);

        $second = $this->post('/api/cards/cabinet/daily');
        self::assertFalse($second['granted']);
        self::assertSame(0, $second['amount']);
        // Not merely "no error" — the balance must be untouched.
        self::assertSame(JetonTariff::DAILY_BASE, $second['balance']);
        self::assertSame(1, $second['streakDays']);
    }

    public function testAStreakGrowsOnConsecutiveDaysAndStartsOverAfterAGap(): void
    {
        $this->login('zz-owner@example.com');
        $this->post('/api/cards/cabinet/daily');

        // Yesterday's grant, so today's continues the streak.
        $this->backdateGrant($this->owner, '-1 day');
        $continued = $this->post('/api/cards/cabinet/daily');
        self::assertTrue($continued['granted']);
        self::assertSame(2, $continued['streakDays']);
        self::assertSame(JetonTariff::DAILY_BASE + JetonTariff::DAILY_STREAK_STEP, $continued['amount']);

        // A day missed: the streak is gone, and the grant is back to its floor.
        $this->backdateGrant($this->owner, '-3 days');
        $broken = $this->post('/api/cards/cabinet/daily');
        self::assertTrue($broken['granted']);
        self::assertSame(1, $broken['streakDays']);
        self::assertSame(JetonTariff::DAILY_BASE, $broken['amount']);
    }

    // --------------------------------------------------------------- the gate

    public function testAPackBelowTheGateIsRefusedInFrenchAndCostsNothing(): void
    {
        $this->login('zz-owner@example.com');
        $this->post('/api/cards/cabinet/daily');

        $this->client->request('POST', '/api/cards/cabinet/packs/free');

        self::assertResponseStatusCodeSame(422);
        $body = $this->decode();
        self::assertStringContainsString('500 œuvres vues', $body['error']);

        $cabinet = $this->get('/api/cards/cabinet');
        self::assertFalse($cabinet['unlocked']);
        self::assertSame(JetonTariff::DAILY_BASE, $cabinet['balance']);
    }

    public function testAPackKindThatDoesNotExistIsNotEvenARoute(): void
    {
        $this->login('zz-owner@example.com');
        $this->client->request('POST', '/api/cards/cabinet/packs/mythique');

        // A 404 rather than a refusal, so the door simply is not there.
        self::assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------ cross-account rules

    public function testOpeningAPackWhileViewingAnotherProfileDrawsFromYourOwnCatalogue(): void
    {
        $this->seedUnlockedCatalogue($this->owner);
        $this->seedUnlockedCatalogue($this->viewer);
        $this->grantAccess($this->owner, $this->viewer);

        $ownerBalanceBefore = $this->balanceOf($this->owner);
        $this->creditTill($this->viewer, 5000);

        $this->login('zz-viewer@example.com');
        $this->client->request('POST', '/api/cards/cabinet/packs/reel?profileId='.$this->owner->getId());

        self::assertResponseStatusCodeSame(201);
        $result = $this->decode();

        // Every card drawn belongs to the viewer's catalogue, not the owner's.
        foreach ($result['cards'] as $card) {
            self::assertSame(
                (string) $this->viewer->getId(),
                $this->ownerOfCard($card['card']['id']),
                'a forged profileId drew from somebody else\'s catalogue'
            );
        }

        // And the viewer paid for it.
        self::assertSame($ownerBalanceBefore, $this->balanceOf($this->owner));
        self::assertLessThan(5000, $this->balanceOf($this->viewer));
    }

    public function testArrangingAShowcaseWhileViewingAnotherProfileArrangesYourOwn(): void
    {
        $this->seedUnlockedCatalogue($this->owner);
        $this->seedUnlockedCatalogue($this->viewer);
        $this->grantAccess($this->owner, $this->viewer);

        $this->login('zz-viewer@example.com');
        $this->client->request('POST', '/api/cards/cabinet/packs/free');
        self::assertResponseStatusCodeSame(201);
        $cardId = $this->decode()['cards'][0]['card']['id'];

        $this->client->request(
            'PUT',
            '/api/cards/cabinet/showcase?profileId='.$this->owner->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['cardIds' => [$cardId]])
        );

        self::assertResponseIsSuccessful();
        self::assertSame($cardId, $this->decode()['slots'][0]['id']);

        // The owner's showcase never moved.
        self::assertSame(
            0,
            (int) $this->entityManager->getConnection()->executeQuery(
                'SELECT COUNT(*) FROM card WHERE user_id = :id AND showcase_position IS NOT NULL',
                ['id' => (string) $this->owner->getId()]
            )->fetchOne()
        );
    }

    public function testAShowcaseCannotBeFilledWithACardNobodyOwns(): void
    {
        $this->seedUnlockedCatalogue($this->owner);
        $this->seedUnlockedCatalogue($this->viewer);

        $strangersCard = (string) $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM card WHERE user_id = :id LIMIT 1',
            ['id' => (string) $this->owner->getId()]
        )->fetchOne();

        $this->login('zz-viewer@example.com');
        $this->client->request(
            'PUT',
            '/api/cards/cabinet/showcase',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['cardIds' => [$strangersCard]])
        );

        self::assertResponseStatusCodeSame(422);
        // Same wording as for a card that does not exist, so a showcase cannot be used to
        // probe another account's catalogue one id at a time.
        self::assertSame('Cette carte n\'est pas encore dans ta collection.', $this->decode()['error']);
    }

    // ------------------------------------------------------------ the showcase

    public function testAGrantedProfileShowcaseIsVisibleAndAnUngrantedOneIsNot(): void
    {
        $this->seedUnlockedCatalogue($this->owner);
        $this->grantAccess($this->owner, $this->viewer);

        $this->login('zz-viewer@example.com');
        $this->client->request('GET', '/api/cards/showcase?profileId='.$this->owner->getId());

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame('zz-owner@example.com', $body['ownerDisplayName']);
        self::assertCount(6, $body['slots']);

        $stranger = $this->account('zz-stranger@example.com');
        $this->entityManager->flush();
        $this->login('zz-stranger@example.com');
        $this->client->request('GET', '/api/cards/showcase?profileId='.$this->owner->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($stranger->getId());
    }

    public function testTheTillIsWithheldWhileLookingAtSomebodyElsesCabinet(): void
    {
        $this->seedUnlockedCatalogue($this->owner);
        $this->grantAccess($this->owner, $this->viewer);
        $this->creditTill($this->owner, 4242);

        $this->login('zz-viewer@example.com');
        $shared = $this->get('/api/cards/cabinet?profileId='.$this->owner->getId());

        // The collection is worth showing…
        self::assertGreaterThan(0, $shared['cardCount']);
        self::assertTrue($shared['unlocked']);
        // …the money is not.
        self::assertNull($shared['balance']);
        self::assertNull($shared['streakDays']);
        self::assertNull($shared['dailyGrantAvailable']);

        $own = $this->get('/api/cards/cabinet');
        self::assertNotNull($own['balance']);
    }

    // ---------------------------------------------------------------- fixture

    private function seedUnlockedCatalogue(User $user): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $movie = new Movie(
                sprintf('zz-cab-%d-%d', ++$this->counter, $i),
                sprintf('ZZ Cabinet %03d', $i)
            );
            $movie->setMediaType(MediaType::MOVIE)
                ->setReleaseYear(1980 + $i)
                ->setPopularity(1.0 + $i)
                ->setTmdbVoteAverage(5.0 + ($i % 5) / 2)
                ->setTmdbVoteCount(100 * ($i + 1));
            $this->entityManager->persist($movie);

            $watch = new Watch($user, $movie, WatchSource::MANUAL);
            $watch->setWatchedDate(new \DateTimeImmutable('2026-02-02'))->setRating(0.5 + 0.5 * ($i % 10));
            $this->entityManager->persist($watch);
        }

        $this->entityManager->flush();
        self::getContainer()->get(CardCatalogueBuilder::class)->rebuild($user);

        // The gate reads a cached count, and building five hundred films for a controller
        // test would be five hundred films of fixture to say one thing about a column.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE card_cabinet SET catalogue_work_count = :works WHERE user_id = :id',
            ['id' => (string) $user->getId(), 'works' => CardPackOpener::MINIMUM_WORKS]
        );
    }

    private function account(string $email): User
    {
        $user = new User($email, $email);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD)
        );
        $this->entityManager->persist($user);

        return $user;
    }

    private function grantAccess(User $owner, User $viewer): void
    {
        $this->entityManager->persist(new ProfileAccess($owner, $viewer));
        $this->entityManager->flush();
    }

    private function creditTill(User $user, int $jetons): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE card_cabinet SET balance = :jetons WHERE user_id = :id',
            ['id' => (string) $user->getId(), 'jetons' => $jetons]
        );
    }

    private function backdateGrant(User $user, string $interval): void
    {
        $this->entityManager->getConnection()->executeStatement(
            sprintf("UPDATE card_cabinet SET last_daily_grant_on = CURRENT_DATE + INTERVAL '%s' WHERE user_id = :id", $interval),
            ['id' => (string) $user->getId()]
        );
    }

    private function balanceOf(User $user): int
    {
        return (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT balance FROM card_cabinet WHERE user_id = :id',
            ['id' => (string) $user->getId()]
        )->fetchOne();
    }

    private function ownerOfCard(string $cardId): string
    {
        return (string) $this->entityManager->getConnection()->executeQuery(
            'SELECT user_id FROM card WHERE id = :id',
            ['id' => $cardId]
        )->fetchOne();
    }

    private function login(string $email): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => self::PASSWORD])
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $uri): array
    {
        $this->client->request('POST', $uri);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
