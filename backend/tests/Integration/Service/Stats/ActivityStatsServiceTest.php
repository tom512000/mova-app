<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\ActivityStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rhythm block answers "which evenings did I spend watching something", so the cases
 * worth pinning are the rows that look like a viewing without being one.
 */
final class ActivityStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActivityStatsService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(ActivityStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('activity@example.com');
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

    public function testEachViewingDayGetsItsOwnSquare(): void
    {
        $this->watched($this->film('un'), '2024-03-06');
        $this->watched($this->film('deux'), '2024-03-06');
        $this->watched($this->film('trois'), '2024-03-08');

        $stats = $this->service->getActivity($this->user);

        self::assertSame(2, $stats->activeDays);
        self::assertSame(2, $stats->busiestDayCount);
        self::assertSame('2024-03-06', $stats->busiestDate);
        self::assertSame(['2024-03-06', '2024-03-08'], array_map(static fn ($d) => $d->date, $stats->calendar));
    }

    public function testARevisedRatingIsNotAnEveningInFrontOfAFilm(): void
    {
        // The library, and then a note moved months later after reading somebody else's
        // review. The square that used to appear on 2024-07-02 had no film behind it — and
        // it now opens the library filtered on that day, which would come back empty.
        $film = $this->film('renote');
        $this->watched($film, '2024-03-06');
        $this->watched($film, '2024-07-02', WatchSource::CSV_RERATING);

        $stats = $this->service->getActivity($this->user);

        self::assertSame(1, $stats->activeDays);
        self::assertSame(['2024-03-06'], array_map(static fn ($d) => $d->date, $stats->calendar));
    }

    public function testARevisedRatingIsNotCountedAgainstAWeekdayEither(): void
    {
        // 2024-03-06 is a Wednesday, 2024-07-02 a Tuesday. Only one of them was an evening.
        $film = $this->film('renote-jour');
        $this->watched($film, '2024-03-06');
        $this->watched($film, '2024-07-02', WatchSource::CSV_RERATING);

        $byWeekday = [];
        foreach ($this->service->getActivity($this->user)->weekdays as $weekday) {
            $byWeekday[$weekday->weekday] = $weekday->watchCount;
        }

        self::assertSame(1, $byWeekday[3], 'the Wednesday it was watched');
        self::assertSame(0, $byWeekday[2], 'the Tuesday the note moved');
    }

    public function testADeclaredRewatchStillCounts(): void
    {
        // The exclusion is about rows nobody declared, not about second viewings. A rewatch
        // Letterboxd wrote a diary entry for is an evening like any other.
        $film = $this->film('revu-pour-de-vrai');
        $this->watched($film, '2024-03-06');
        $this->watched($film, '2024-09-14');

        self::assertSame(2, $this->service->getActivity($this->user)->activeDays);
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-activity@example.com');
        $this->watched($this->film('pas-a-moi'), '2024-03-06', user: $other);

        self::assertSame(0, $this->service->getActivity($this->user)->activeDays);
    }

    private function film(string $title): Movie
    {
        $movie = new Movie('zz-activity-'.$title, $title);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function watched(
        Movie $movie,
        string $watchedDate,
        WatchSource $source = WatchSource::CSV_IMPORT,
        ?User $user = null,
    ): void {
        $watch = new Watch($user ?? $this->user, $movie, $source);
        $watch->setWatchedDate(new \DateTimeImmutable($watchedDate));
        $this->entityManager->persist($watch);
        $this->entityManager->flush();
    }

    private function createUser(string $email): User
    {
        $user = new User($email, $email);
        $user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
