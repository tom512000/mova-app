<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Franchise;
use App\Entity\FranchiseFilm;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\FranchiseStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The block exists to say what is left to watch, so what needs pinning is what counts as
 * left — and the order, which is the only thing that makes it a to-do list rather than
 * another tally.
 */
final class FranchiseStatsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FranchiseStatsService $service;
    private User $user;
    private int $tmdbId = 940000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FranchiseStatsService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('franchises@example.com');
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

    public function testAFinishedSagaIsNotSomethingToFinish(): void
    {
        $saga = $this->saga('Complete', ['Un', 'Deux']);
        $this->watched($this->filmOf($saga, 'Un'));
        $this->watched($this->filmOf($saga, 'Deux'));

        self::assertSame([], $this->service->getIncompleteFranchises($this->user));
    }

    public function testTheSagaWithLeastLeftComesFirst(): void
    {
        // The ordering is the point: one film left is something you might do tonight, five
        // is a project. A block sorted by what has been watched would bury the actionable end.
        $nearlyDone = $this->saga('Presque finie', ['A', 'B', 'C']);
        $this->watched($this->filmOf($nearlyDone, 'A'));
        $this->watched($this->filmOf($nearlyDone, 'B'));

        $barelyStarted = $this->saga('A peine commencee', ['D', 'E', 'F', 'G']);
        $this->watched($this->filmOf($barelyStarted, 'D'));

        $stats = $this->service->getIncompleteFranchises($this->user);

        self::assertSame(['Presque finie', 'A peine commencee'], array_map(static fn ($s) => $s->name, $stats));
        self::assertSame(2, $stats[0]->watchedCount);
        self::assertSame(3, $stats[0]->totalCount);
    }

    public function testTheMissingTitlesAreNamedOldestFirst(): void
    {
        // "Two of four" without saying which two is the half of the answer nobody can act on.
        $saga = $this->saga('Nommee', ['Ancien', 'Milieu', 'Recent'], ['2001-01-01', '2005-01-01', '2010-01-01']);
        $this->watched($this->filmOf($saga, 'Milieu'));

        $stats = $this->service->getIncompleteFranchises($this->user);

        self::assertSame(['Ancien', 'Recent'], $stats[0]->missing);
    }

    public function testAFilmOwnedButNeverWatchedIsStillMissing(): void
    {
        // The block answers "what have I not seen", not "what do I not have" - a film parked
        // in the watchlist has not been seen, and saying otherwise would be a lie by tally.
        $saga = $this->saga('Watchlist', ['Vu', 'En attente']);
        $this->watched($this->filmOf($saga, 'Vu'));
        // In the library, no viewing: exactly what a watchlist entry looks like.
        $this->filmOf($saga, 'En attente');

        $stats = $this->service->getIncompleteFranchises($this->user);

        self::assertSame(1, $stats[0]->watchedCount);
        self::assertSame(['En attente'], $stats[0]->missing);
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('somebody-else-franchises@example.com');
        $saga = $this->saga('Pas a moi', ['Un', 'Deux']);
        $this->watched($this->filmOf($saga, 'Un'), user: $other);

        self::assertSame([], $this->service->getIncompleteFranchises($this->user));
    }

    /**
     * A saga and the films TMDB lists in it. Nothing is in the library yet — filmOf() puts
     * one there.
     *
     * @param list<string> $titles
     * @param list<string> $dates
     */
    private function saga(string $name, array $titles, array $dates = []): Franchise
    {
        $saga = (new Franchise())->setTmdbId(++$this->tmdbId)->setName($name);
        $this->entityManager->persist($saga);

        foreach ($titles as $index => $title) {
            $part = new FranchiseFilm($saga, ++$this->tmdbId, $title);
            if (isset($dates[$index])) {
                $part->setReleaseDate(new \DateTimeImmutable($dates[$index]));
            }
            $saga->addFilm($part);
            $this->entityManager->persist($part);
        }

        $this->entityManager->flush();

        return $saga;
    }

    /** Puts one of the saga's films into the library, matched to its entry by TMDB id. */
    private function filmOf(Franchise $saga, string $title): Movie
    {
        $part = null;
        foreach ($saga->getFilms() as $candidate) {
            if ($candidate->getTitle() === $title) {
                $part = $candidate;
                break;
            }
        }
        self::assertNotNull($part, "the saga has no film called {$title}");

        $movie = new Movie('zz-saga-'.$part->getTmdbId(), $title);
        $movie->setTmdbId($part->getTmdbId());
        $movie->setMediaType(MediaType::MOVIE);
        $movie->setFranchise($saga);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function watched(Movie $movie, ?User $user = null): void
    {
        $watch = new Watch($user ?? $this->user, $movie, WatchSource::CSV_IMPORT);
        $watch->setRating(4.0);
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
