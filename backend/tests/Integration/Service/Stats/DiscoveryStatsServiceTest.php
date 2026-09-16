<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\DiscoveryStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Discovered this year" hangs entirely on one word: *first*.
 *
 * Every other per-year block filters the works; this one filters the person, on the earliest
 * work of theirs in the whole library. Get that wrong and the block silently becomes the
 * most-watched ranking with a date stapled to it — which is exactly what it must not be, and
 * exactly what nobody would notice from looking at it.
 */
final class DiscoveryStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DiscoveryStatsService $service;
    private User $user;
    private int $counter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(DiscoveryStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('discoveries@example.com');
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testSomebodySeenBeforeIsNeverADiscovery(): void
    {
        // Watched once in 2025 and four more times in 2026. A block counting works *of the
        // year* would make them the discovery of 2026; they were met in 2025.
        $familiar = $this->person('ZZ Deja Vu');
        $this->credited($familiar, CreditRole::ACTOR, '2025-11-02');
        foreach (['2026-01-04', '2026-02-04', '2026-03-04', '2026-04-04'] as $date) {
            $this->credited($familiar, CreditRole::ACTOR, $date);
        }

        self::assertSame([], $this->namesFor(2026));
        self::assertSame(['ZZ Deja Vu'], $this->namesFor(2025));
    }

    public function testTheDiscoveryIsDatedToTheFirstWorkNotTheLatest(): void
    {
        $person = $this->person('ZZ Nouveau');
        $this->credited($person, CreditRole::ACTOR, '2026-03-04');
        $this->credited($person, CreditRole::ACTOR, '2026-09-30');

        $discovery = $this->service->getDiscoveries($this->user, 2026)[0];

        self::assertSame('2026-03-04', $discovery->firstSeenOn);
        self::assertSame(2, $discovery->workCount);
    }

    public function testWhatFollowedTheMeetingDecidesTheOrder(): void
    {
        // Everybody here met the library exactly once, so the date orders them by nothing.
        $many = $this->person('ZZ Trois Films');
        foreach (['2026-05-01', '2026-05-02', '2026-05-03'] as $date) {
            $this->credited($many, CreditRole::ACTOR, $date);
        }

        $one = $this->person('ZZ Un Film');
        $this->credited($one, CreditRole::ACTOR, '2026-01-01');

        self::assertSame(['ZZ Trois Films', 'ZZ Un Film'], $this->namesFor(2026));
    }

    public function testDirectingOutranksActingForSomebodyWhoDoesBoth(): void
    {
        $person = $this->person('ZZ Double Casquette');
        $film = $this->credited($person, CreditRole::ACTOR, '2026-02-02');
        // The same film, a second credit. It must not count as a second work either.
        $this->credit($film, $person, CreditRole::DIRECTOR);
        $this->entityManager->flush();

        $discovery = $this->service->getDiscoveries($this->user, 2026)[0];

        self::assertSame(CreditRole::DIRECTOR, $discovery->role);
        self::assertSame(1, $discovery->workCount, 'two credits on one film are one work');
    }

    public function testAProducerCreditIsNotAReasonAnybodyPickedAFilm(): void
    {
        // The same rule the retrospective's person of the year follows: counting production
        // hands a year to an executive nobody watched anything for.
        $producer = $this->person('ZZ Productrice');
        $film = $this->movie('2026-06-06');
        $this->credit($film, $producer, CreditRole::PRODUCER);
        $this->entityManager->flush();

        self::assertSame([], $this->namesFor(2026));
    }

    public function testARevisedRatingCannotMoveACareerIntoTheWrongYear(): void
    {
        $person = $this->person('ZZ Note Revisee');
        $film = $this->credited($person, CreditRole::ACTOR, '2026-04-04');

        // A note deduced from a re-rating, dated years earlier. Read as a viewing it would
        // make this a discovery of 2019.
        $revision = new Watch($this->user, $film, WatchSource::CSV_RERATING);
        $revision->setWatchedDate(new \DateTimeImmutable('2019-01-01'));
        $revision->setRating(4.0);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        self::assertSame([], $this->namesFor(2019));
        self::assertSame(['ZZ Note Revisee'], $this->namesFor(2026));
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $stranger = $this->createUser('discoveries-stranger@example.com');
        $person = $this->person('ZZ Vu Ailleurs');

        $film = $this->movie(null);
        $this->credit($film, $person, CreditRole::ACTOR);
        $watch = new Watch($stranger, $film, WatchSource::MANUAL);
        $watch->setWatchedDate(new \DateTimeImmutable('2026-07-07'));
        $this->entityManager->persist($watch);
        $this->entityManager->flush();

        self::assertSame([], $this->namesFor(2026));
    }

    /**
     * @return list<string>
     */
    private function namesFor(int $year): array
    {
        return array_map(
            static fn ($discovery) => $discovery->name,
            $this->service->getDiscoveries($this->user, $year)
        );
    }

    /** A fresh film, credited to them, watched on that day. */
    private function credited(Person $person, CreditRole $role, string $watchedOn): Movie
    {
        $movie = $this->movie($watchedOn);
        $this->credit($movie, $person, $role);
        $this->entityManager->flush();

        return $movie;
    }

    private function movie(?string $watchedOn): Movie
    {
        $movie = new Movie('test-discovery-'.++$this->counter, 'ZZ Film '.$this->counter);
        $this->entityManager->persist($movie);

        if (null !== $watchedOn) {
            $watch = new Watch($this->user, $movie, WatchSource::MANUAL);
            $watch->setWatchedDate(new \DateTimeImmutable($watchedOn));
            $watch->setRating(3.5);
            $this->entityManager->persist($watch);
        }

        $this->entityManager->flush();

        return $movie;
    }

    private function credit(Movie $movie, Person $person, CreditRole $role): void
    {
        $credit = new Credit($movie, $person, $role);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
    }

    private function person(string $name): Person
    {
        $person = (new Person())->setName($name);
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        return $person;
    }

    private function createUser(string $email): User
    {
        $user = new User($email, 'Discoveries');
        $user->setPassword('irrelevant');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
