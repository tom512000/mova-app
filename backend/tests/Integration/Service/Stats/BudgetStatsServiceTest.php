<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\BudgetStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bracketing is the whole of this service, so the cases that matter are the edges of the
 * brackets and the rows that do not belong in any of them.
 */
final class BudgetStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BudgetStatsService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(BudgetStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('budgets@example.com');
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

    public function testEachBracketTakesItsOwnFilms(): void
    {
        $this->watched($this->film('minuscule', 1_000_000), 3.0);
        $this->watched($this->film('moyen', 20_000_000), 4.0);
        $this->watched($this->film('gros', 50_000_000), 5.0);
        $this->watched($this->film('enorme', 200_000_000), 2.0);

        $bands = $this->service->getBudgetStats($this->user)->bands;

        self::assertSame([1, 1, 1, 1], array_map(static fn ($b) => $b->movieCount, $bands));
        self::assertSame([3.0, 4.0, 5.0, 2.0], array_map(static fn ($b) => $b->averageRating, $bands));
    }

    public function testABudgetExactlyOnABoundaryGoesToTheBracketAbove(): void
    {
        // The upper bound is exclusive, so five million is the first film of the second
        // bracket rather than the last of the first. Worth pinning: an off-by-one here moves
        // films between brackets silently, and nothing on screen would look wrong.
        $this->watched($this->film('pile', 5_000_000), 4.0);

        $bands = $this->service->getBudgetStats($this->user)->bands;

        self::assertSame(0, $bands[0]->movieCount);
        self::assertSame(1, $bands[1]->movieCount);
    }

    public function testAllFourBracketsComeBackEvenWhenEmpty(): void
    {
        $this->watched($this->film('seul', 20_000_000), 4.0);

        $bands = $this->service->getBudgetStats($this->user)->bands;

        self::assertCount(4, $bands);
        self::assertSame(0, $bands[0]->movieCount);
        // Zero is a rating; an empty bracket has not earned one, and a chart that draws 0
        // for "no data" invents a verdict.
        self::assertNull($bands[0]->averageRating);
    }

    public function testTheBracketsRunFromZeroToOpenEnded(): void
    {
        $bands = $this->service->getBudgetStats($this->user)->bands;

        self::assertSame(0, $bands[0]->minBudget);
        self::assertSame(5_000_000, $bands[0]->maxBudget);
        self::assertSame(100_000_000, $bands[3]->minBudget);
        self::assertNull($bands[3]->maxBudget, 'the top bracket has no ceiling');
    }

    public function testAMissingBudgetIsNotAFilmMadeForNothing(): void
    {
        // Zero means "nobody filled it in" on TMDB, not "cost nothing". Counting it in the
        // bottom bracket would drag that average towards whatever such films are rated.
        $this->watched($this->film('sans-budget', null), 1.0);
        $this->watched($this->film('budget-zero', 0), 1.0);
        $this->watched($this->film('renseigne', 1_000_000), 5.0);

        $stats = $this->service->getBudgetStats($this->user);

        self::assertSame(1, $stats->bands[0]->movieCount);
        self::assertSame(5.0, $stats->bands[0]->averageRating);
        self::assertSame(2, $stats->worksWithoutBudget, 'reported apart rather than dropped silently');
    }

    public function testAFilmWatchedTwiceIsStillOneFilm(): void
    {
        $film = $this->film('revu', 20_000_000);
        $this->watched($film, 3.0);
        $this->watched($film, 5.0);

        $bands = $this->service->getBudgetStats($this->user)->bands;

        self::assertSame(1, $bands[1]->movieCount);
        self::assertSame(4.0, $bands[1]->averageRating, 'both viewings weigh on the score');
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-budgets@example.com');
        $this->watched($this->film('pas-a-moi', 20_000_000), 5.0, user: $other);
        $this->watched($this->film('pas-a-moi-non-plus', null), 5.0, user: $other);

        $stats = $this->service->getBudgetStats($this->user);

        self::assertSame([0, 0, 0, 0], array_map(static fn ($b) => $b->movieCount, $stats->bands));
        self::assertSame(0, $stats->worksWithoutBudget);
    }

    private function film(string $title, ?int $budget): Movie
    {
        $movie = new Movie('zz-budget-'.$title, $title);
        $movie->setBudget(null === $budget ? null : (string) $budget);
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
