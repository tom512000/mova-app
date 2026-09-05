<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\DecadeStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The decades block draws one bar per decade and prints the average score above it, so what
 * needs pinning is the shape of the axis — which decades appear, and what an empty one holds.
 */
final class DecadeStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DecadeStatsService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(DecadeStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('decades@example.com');
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

    public function testFilmsFallIntoTheDecadeTheyWereReleasedIn(): void
    {
        $this->watched($this->film('un', 1994), 4.0);
        $this->watched($this->film('deux', 1999), 3.0);

        $stats = $this->service->getDecadeStats($this->user);

        self::assertCount(1, $stats);
        self::assertSame(1990, $stats[0]->decade);
        self::assertSame(2, $stats[0]->movieCount);
        self::assertSame(3.5, $stats[0]->averageRating);
    }

    public function testADecadeWithNothingInItIsStillOnTheAxis(): void
    {
        // A chart that skips from 1970 to 1990 reads as "not much in between". The gap is
        // the interesting part, so it gets a column of its own with a zero in it.
        $this->watched($this->film('vieux', 1975), 4.0);
        $this->watched($this->film('recent', 1995), 3.0);

        $stats = $this->service->getDecadeStats($this->user);

        self::assertSame([1970, 1980, 1990], array_map(static fn ($d) => $d->decade, $stats));
        self::assertSame(0, $stats[1]->movieCount);
    }

    public function testTheAxisStopsAtTheOldestAndNewestFilmWatched(): void
    {
        // Only the interior is filled. Padding out to a round century would draw empty
        // decades that say nothing about this library.
        $this->watched($this->film('seul', 2003), 4.0);

        $stats = $this->service->getDecadeStats($this->user);

        self::assertSame([2000], array_map(static fn ($d) => $d->decade, $stats));
    }

    public function testAnEmptyDecadeHasNoScoreRatherThanAZeroOne(): void
    {
        // Zero is a terrible rating; the absence of one is not. The chart has to be able to
        // tell them apart, or it invents a verdict about films nobody watched.
        $this->watched($this->film('avant', 1975), 4.0);
        $this->watched($this->film('apres', 1995), 3.0);

        self::assertNull($this->service->getDecadeStats($this->user)[1]->averageRating);
    }

    public function testAFilmWithNoReleaseYearIsLeftOut(): void
    {
        $this->watched($this->film('date', 2003), 4.0);
        $this->watched($this->film('sans-date', null), 1.0);

        $stats = $this->service->getDecadeStats($this->user);

        self::assertCount(1, $stats);
        self::assertSame(1, $stats[0]->movieCount);
        self::assertSame(4.0, $stats[0]->averageRating, 'the undated film must not drag the average down');
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-decades@example.com');
        $this->watched($this->film('pas-a-moi', 2003), 5.0, user: $other);

        self::assertSame([], $this->service->getDecadeStats($this->user));
    }

    private function film(string $title, ?int $releaseYear): Movie
    {
        $movie = new Movie('zz-decade-'.$title, $title);
        $movie->setReleaseYear($releaseYear);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function watched(Movie $movie, ?float $rating, ?User $user = null): void
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
