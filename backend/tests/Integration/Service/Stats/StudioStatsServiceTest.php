<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\StudioStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What needs pinning here is the counting rule, because it is the surprising part: a film
 * belongs to every studio credited on it, so the column adds up to more than the library.
 */
final class StudioStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StudioStatsService $service;
    private User $user;
    private int $tmdbId = 970000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(StudioStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('studios@example.com');
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

    public function testACoProductionCountsForEveryStudioOnIt(): void
    {
        // The rule the block has to state under its title. Nothing in TMDB marks one company
        // as the real one, so a film financed by three of them is one film for each.
        $lead = $this->studio('Studio');
        $broadcaster = $this->studio('Chaine');

        $film = $this->film('coproduction', $lead, $broadcaster);
        $this->watched($film, 4.0);

        $stats = $this->service->getStudioStats($this->user);

        self::assertCount(2, $stats);
        self::assertSame(1, $stats[0]->movieCount);
        self::assertSame(1, $stats[1]->movieCount);
    }

    public function testTheBusiestStudioComesFirst(): void
    {
        $prolific = $this->studio('Prolifique');
        $rare = $this->studio('Rare');

        $this->watched($this->film('un', $prolific), 2.0);
        $this->watched($this->film('deux', $prolific), 4.0);
        $this->watched($this->film('trois', $rare), 5.0);

        $stats = $this->service->getStudioStats($this->user);

        self::assertSame(['Prolifique', 'Rare'], array_map(static fn ($s) => $s->name, $stats));
        self::assertSame(2, $stats[0]->movieCount);
        self::assertSame(3.0, $stats[0]->averageRating, 'averaged over its films, not over the library');
    }

    public function testAFilmWatchedTwiceIsStillOneFilmForItsStudio(): void
    {
        // The count is DISTINCT on the film. A rewatch says something about the evening, not
        // about how much of this studio's catalogue was seen.
        $studio = $this->studio('Revu');
        $film = $this->film('revu', $studio);
        $this->watched($film, 3.0);
        $this->watched($film, 5.0);

        $stats = $this->service->getStudioStats($this->user);

        self::assertSame(1, $stats[0]->movieCount);
        self::assertSame(4.0, $stats[0]->averageRating, 'both viewings weigh on the score');
    }

    public function testTheLimitCutsTheTailRatherThanTheHead(): void
    {
        $this->watched($this->film('gros-un', $big = $this->studio('Gros')), 4.0);
        $this->watched($this->film('gros-deux', $big), 4.0);
        $this->watched($this->film('petit-un', $this->studio('Petit')), 4.0);

        $stats = $this->service->getStudioStats($this->user, 1);

        self::assertCount(1, $stats);
        self::assertSame('Gros', $stats[0]->name);
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-studios@example.com');
        $this->watched($this->film('pas-a-moi', $this->studio('Ailleurs')), 5.0, user: $other);

        self::assertSame([], $this->service->getStudioStats($this->user));
    }

    private function studio(string $name): Studio
    {
        $studio = (new Studio())->setTmdbId(++$this->tmdbId)->setName($name);
        $this->entityManager->persist($studio);
        $this->entityManager->flush();

        return $studio;
    }

    private function film(string $title, Studio ...$studios): Movie
    {
        $movie = new Movie('zz-studio-'.$title, $title);
        foreach ($studios as $studio) {
            $movie->addStudio($studio);
        }
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
