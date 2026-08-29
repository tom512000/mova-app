<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\ReleaseWindowStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Seen at release" is a claim about the first time you saw a film, so the interesting
 * cases are the ones where a later viewing could be mistaken for it — and the films that
 * can never qualify, which must not be counted against the tally either.
 */
final class ReleaseWindowStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReleaseWindowStatsService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(ReleaseWindowStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('release@example.com');
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

    public function testAFilmCaughtInItsFirstMonthCounts(): void
    {
        $this->seen($this->film('sortie-proche', '2024-03-06'), '2024-03-09');

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(1, $stats->count);
        self::assertSame(1, $stats->firstWeek);
        self::assertSame(3, $stats->movies[0]->daysAfterRelease);
        self::assertSame('2024-03-09', $stats->movies[0]->firstWatchedDate);
    }

    public function testTheWindowIsAMonthAndItsEdgeIsIncluded(): void
    {
        $this->seen($this->film('dernier-jour', '2024-03-06'), '2024-04-06'); // 31 days
        $this->seen($this->film('un-jour-trop-tard', '2024-03-06'), '2024-04-07'); // 32

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(ReleaseWindowStatsService::WITHIN_DAYS, $stats->withinDays);
        self::assertSame(1, $stats->count);
        self::assertSame('dernier-jour', $stats->movies[0]->title);
        self::assertSame(0, $stats->firstWeek, 'a month is not a week');
    }

    public function testARewatchCannotMakeAFilmSeenAtRelease(): void
    {
        // Discovered a decade late, then rewatched. The first viewing is what is claimed.
        $movie = $this->film('vu-trop-tard', '2010-01-06');
        $this->seen($movie, '2023-05-01');
        $this->seen($movie, '2023-05-20', rewatch: true);

        self::assertSame(0, $this->service->getReleaseWindowStats($this->user)->count);
    }

    public function testALaterRewatchDoesNotUnseatTheFirstViewing(): void
    {
        // The reverse case: caught on release and seen again years on. Still counts once,
        // and still at the gap the first viewing earned.
        $movie = $this->film('revu-plus-tard', '2020-02-05');
        $this->seen($movie, '2020-02-07');
        $this->seen($movie, '2024-11-02', rewatch: true);

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(1, $stats->count);
        self::assertSame(2, $stats->movies[0]->daysAfterRelease);
    }

    public function testAFilmSeenBeforeItsReleaseDateIsLeftOut(): void
    {
        // A preview, or a release date TMDB has for another territory. Either way it is not
        // a gap this can measure honestly, so it is not claimed.
        $this->seen($this->film('avant-premiere', '2024-03-06'), '2024-03-01');

        self::assertSame(0, $this->service->getReleaseWindowStats($this->user)->count);
    }

    public function testAFilmWithNoReleaseDateIsNotHeldAgainstTheTally(): void
    {
        $this->seen($this->film('sortie-proche', '2024-03-06'), '2024-03-09');
        $this->seen($this->film('sans-date', null), '2024-03-09');
        // A watch with no date of its own cannot be placed either.
        $this->seen($this->film('sans-date-de-visionnage', '2024-03-06'), null);

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(1, $stats->count);
        // The denominator is the films this could be asked of, not the whole library.
        self::assertSame(1, $stats->comparable);
    }

    public function testTheClosestToReleaseComesFirst(): void
    {
        $this->seen($this->film('trois-semaines', '2024-03-06'), '2024-03-27');
        $this->seen($this->film('le-jour-meme', '2024-03-06'), '2024-03-06');
        $this->seen($this->film('la-semaine-suivante', '2024-03-06'), '2024-03-11');

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(
            ['le-jour-meme', 'la-semaine-suivante', 'trois-semaines'],
            array_map(static fn ($movie) => $movie->title, $stats->movies)
        );
        self::assertSame(0, $stats->movies[0]->daysAfterRelease, 'the day itself is a gap of zero');
        self::assertSame(2, $stats->firstWeek);
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else@example.com');
        $movie = $this->film('sortie-proche', '2024-03-06');

        $this->seen($movie, '2024-03-09', user: $other);

        $stats = $this->service->getReleaseWindowStats($this->user);
        self::assertSame(0, $stats->count);
        self::assertSame(0, $stats->comparable, 'not even the denominator is shared');
    }

    public function testTheGapIsMeasuredFromTheFrenchReleaseWhenThereIsOne(): void
    {
        // Opened in the United States on the 6th and here three weeks later. Seen on the
        // 25th, that is four days after it could be seen at all — not twenty-five.
        $film = $this->film('sortie-decalee', '2024-03-06');
        $film->setFrenchReleaseDate(new \DateTimeImmutable('2024-03-21'));
        $this->entityManager->flush();

        $this->seen($film, '2024-03-25');

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(4, $stats->movies[0]->daysAfterRelease);
        self::assertSame(1, $stats->firstWeek, 'four days is inside the first week');
        self::assertSame('2024-03-21', $stats->movies[0]->releaseDate);
    }

    public function testAFilmOnlyReachesTheWindowThroughItsFrenchDate(): void
    {
        // J+40 from the primary release, J+25 from the French one. It belongs in the month.
        $film = $this->film('entre-par-la-france', '2024-03-06');
        $film->setFrenchReleaseDate(new \DateTimeImmutable('2024-03-21'));
        $this->entityManager->flush();

        $this->seen($film, '2024-04-15');

        self::assertSame(1, $this->service->getReleaseWindowStats($this->user)->count);
    }

    public function testAFilmWithNoFrenchReleaseFallsBackInsteadOfDisappearing(): void
    {
        // Straight to streaming here, so TMDB has no French theatrical date. Dropping it
        // would have cost this library three films to gain one — the fallback is the point.
        $this->seen($this->film('direct-en-streaming', '2024-03-06'), '2024-03-09');

        $stats = $this->service->getReleaseWindowStats($this->user);

        self::assertSame(1, $stats->count);
        self::assertSame(3, $stats->movies[0]->daysAfterRelease);
        self::assertSame('2024-03-06', $stats->movies[0]->releaseDate);
    }

    public function testAFrenchReleaseAfterTheViewingLeavesTheFilmOut(): void
    {
        // Seen at a festival before it opened here. Negative gaps were already excluded from
        // the primary date; reading the French one must not smuggle them back in.
        $film = $this->film('vu-en-avant-premiere', '2024-03-06');
        $film->setFrenchReleaseDate(new \DateTimeImmutable('2024-06-01'));
        $this->entityManager->flush();

        $this->seen($film, '2024-03-09');

        self::assertSame(0, $this->service->getReleaseWindowStats($this->user)->count);
    }

    private function film(string $title, ?string $releaseDate): Movie
    {
        $movie = new Movie('zz-release-'.$title, $title);
        if (null !== $releaseDate) {
            $movie->setReleaseDate(new \DateTimeImmutable($releaseDate));
            $movie->setReleaseYear((int) substr($releaseDate, 0, 4));
        }
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function seen(Movie $movie, ?string $watchedDate, bool $rewatch = false, ?User $user = null): void
    {
        $watch = new Watch($user ?? $this->user, $movie, WatchSource::CSV_IMPORT);
        $watch->setIsRewatch($rewatch);
        if (null !== $watchedDate) {
            $watch->setWatchedDate(new \DateTimeImmutable($watchedDate));
        }
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
