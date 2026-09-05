<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\DivergenceStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The block subtracts two numbers that are not on the same scale to begin with, and throws
 * away most of the library before doing it. Both of those are what needs pinning.
 */
final class DivergenceStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DivergenceStatsService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(DivergenceStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('divergence@example.com');
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

    public function testThePublicScoreIsHalvedOntoTheSameScaleBeforeSubtracting(): void
    {
        // TMDB rates out of ten. Subtracting 6.0 from 4.5 raw would report a two-and-a-half
        // star disagreement where there is in fact a star and a half.
        $this->rated($this->work('barème', tmdbAverage: 6.0), 4.5);

        $stats = $this->service->getDivergence($this->user);

        self::assertSame(4.5, $stats->above[0]->yourRating);
        self::assertSame(3.0, $stats->above[0]->publicRating);
        self::assertSame(1.5, $stats->above[0]->gap);
    }

    public function testAWorkTooFewPeopleRatedIsNotCompared(): void
    {
        // Without the floor the ranking fills with obscurities scored by a handful of
        // people, where a wide gap means only that a handful of strangers disagreed.
        $this->rated($this->work('confidentiel', tmdbAverage: 2.0, tmdbVotes: 49), 5.0);
        $this->rated($this->work('connu', tmdbAverage: 6.0, tmdbVotes: 50), 4.0);

        $stats = $this->service->getDivergence($this->user);

        self::assertSame(1, $stats->comparableCount);
        self::assertSame('connu', $stats->above[0]->title);
        self::assertSame(50, $stats->minimumVotes, 'the floor travels with the answer');
    }

    public function testAgreeingWithThePublicPutsAWorkInNeitherColumn(): void
    {
        // A gap of zero is not a small disagreement, it is the absence of one. Taking the
        // two ends of a sorted list would have shown it as both a favourite and a let-down.
        $this->rated($this->work('accord', tmdbAverage: 8.0), 4.0);

        $stats = $this->service->getDivergence($this->user);

        self::assertSame([], $stats->above);
        self::assertSame([], $stats->below);
        self::assertSame(1, $stats->comparableCount, 'it was still compared, it just agreed');
    }

    public function testEachColumnLeadsWithItsWidestDisagreement(): void
    {
        $this->rated($this->work('aime-un-peu', tmdbAverage: 6.0), 4.0);
        $this->rated($this->work('aime-beaucoup', tmdbAverage: 4.0), 5.0);
        $this->rated($this->work('deteste-un-peu', tmdbAverage: 6.0), 2.0);
        $this->rated($this->work('deteste-beaucoup', tmdbAverage: 9.0), 1.0);

        $stats = $this->service->getDivergence($this->user);

        self::assertSame(['aime-beaucoup', 'aime-un-peu'], array_map(static fn ($w) => $w->title, $stats->above));
        // Reversed out of the shared ordering: the widest disagreement has to lead here too,
        // not trail the column.
        self::assertSame(['deteste-beaucoup', 'deteste-un-peu'], array_map(static fn ($w) => $w->title, $stats->below));
    }

    public function testEachColumnStopsAtFive(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            $this->rated($this->work("aime-{$i}", tmdbAverage: 2.0), 5.0 - $i / 10);
            $this->rated($this->work("deteste-{$i}", tmdbAverage: 9.0), 0.5 + $i / 10);
        }

        $stats = $this->service->getDivergence($this->user);

        self::assertCount(5, $stats->above);
        self::assertCount(5, $stats->below);
        self::assertSame(14, $stats->comparableCount, 'the population is the whole set, not what is shown');
    }

    public function testAnUnratedViewingHasNothingToCompare(): void
    {
        $this->rated($this->work('sans-note', tmdbAverage: 8.0), null);

        $stats = $this->service->getDivergence($this->user);

        self::assertSame(0, $stats->comparableCount);
    }

    public function testAnotherAccountsRatingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-divergence@example.com');
        $this->rated($this->work('pas-a-moi', tmdbAverage: 2.0), 5.0, user: $other);

        self::assertSame(0, $this->service->getDivergence($this->user)->comparableCount);
    }

    private function work(string $title, float $tmdbAverage, int $tmdbVotes = 500): Movie
    {
        $movie = new Movie('zz-divergence-'.$title, $title);
        $movie->setTmdbVoteAverage($tmdbAverage);
        $movie->setTmdbVoteCount($tmdbVotes);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function rated(Movie $movie, ?float $rating, ?User $user = null): void
    {
        $watch = new Watch($user ?? $this->user, $movie, WatchSource::CSV_IMPORT);
        $watch->setRating($rating);
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
