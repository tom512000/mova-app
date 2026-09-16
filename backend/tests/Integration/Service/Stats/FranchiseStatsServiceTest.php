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

    public function testAFilmNobodyCanWatchYetIsNotMissing(): void
    {
        // Thirty-seven of this library's seventy-one "unfinished" sagas were finished and
        // waiting on an announcement. A saga you have seen every released film of is done,
        // and "2 / 3" for it is not a to-do list, it is a wrong number.
        $saga = $this->saga('Suite annoncee', ['Un', 'Deux', 'Le troisieme'], [2 => null]);
        $this->watched($this->filmOf($saga, 'Un'));
        $this->watched($this->filmOf($saga, 'Deux'));

        self::assertSame([], $this->service->getIncompleteFranchises($this->user));
    }

    public function testTheUpcomingFilmComesBackWhenAskedFor(): void
    {
        $saga = $this->saga('Suite annoncee', ['Un', 'Deux', 'Le troisieme'], [2 => null]);
        $this->watched($this->filmOf($saga, 'Un'));
        $this->watched($this->filmOf($saga, 'Deux'));

        $stats = $this->service->getIncompleteFranchises($this->user, 12, includeUpcoming: true);

        self::assertCount(1, $stats);
        self::assertSame(2, $stats[0]->watchedCount);
        self::assertSame(3, $stats[0]->totalCount);
        self::assertSame(['Le troisieme'], $stats[0]->missing);
        // Named apart, so the card can say it is coming rather than let it read as something
        // somebody forgot to watch.
        self::assertSame(['Le troisieme'], $stats[0]->upcoming);
        self::assertSame(1, $stats[0]->upcomingCount);
    }

    public function testAWatchedFilmCountsEvenWithoutItsSagaStamp(): void
    {
        // The bug this block shipped with. The tally used to come from movie.franchise_id
        // while the missing titles came from the TMDB id, and the backfill that stamps that
        // foreign key does not reach every film: Bad Boys 2 sat in the library, watched and
        // unstamped, so the saga claimed one film short and could not name which.
        $saga = $this->saga('Bad Boys', ['Un', 'Deux']);

        $stamped = $this->filmOf($saga, 'Un');
        $this->watched($stamped);

        $unstamped = $this->filmOf($saga, 'Deux');
        $unstamped->setFranchise(null);
        $this->entityManager->flush();
        $this->watched($unstamped);

        self::assertSame([], $this->service->getIncompleteFranchises($this->user), 'both films are watched');
    }

    public function testTheTallyAndTheNamedTitlesCannotDisagree(): void
    {
        // The other half of the same bug: whatever the counts say is missing, the card has
        // to be able to name it. An empty list under "il t'en manque 1" is the symptom.
        $saga = $this->saga('Mission', ['Un', 'Deux', 'Trois']);
        $this->watched($this->filmOf($saga, 'Un'));

        $unstamped = $this->filmOf($saga, 'Deux');
        $unstamped->setFranchise(null);
        $this->entityManager->flush();
        $this->watched($unstamped);

        $stats = $this->service->getIncompleteFranchises($this->user);

        self::assertCount(1, $stats);
        self::assertSame(2, $stats[0]->watchedCount);
        self::assertSame(3, $stats[0]->totalCount);
        self::assertSame(['Trois'], $stats[0]->missing);
        self::assertCount(
            $stats[0]->totalCount - $stats[0]->watchedCount,
            $stats[0]->missing,
            'what is counted as missing is what gets named'
        );
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
     * Every film is out by default, and given a date to say so. That is load-bearing now:
     * the service reads a missing date as "announced, not released" and leaves such a film
     * out of the tally, which is exactly what TMDB's undated rows are — "Untitled James
     * Bond Film", "Gladiator III". A fixture without dates would be testing sagas made
     * entirely of films nobody can watch.
     *
     * @param list<string>              $titles
     * @param array<int, string|null>   $dates  a date per film, or null to make it upcoming
     */
    private function saga(string $name, array $titles, array $dates = []): Franchise
    {
        $saga = (new Franchise())->setTmdbId(++$this->tmdbId)->setName($name);
        $this->entityManager->persist($saga);

        foreach ($titles as $index => $title) {
            $part = new FranchiseFilm($saga, ++$this->tmdbId, $title);

            $date = \array_key_exists($index, $dates) ? $dates[$index] : '2001-01-01';
            if (null !== $date) {
                $part->setReleaseDate(new \DateTimeImmutable($date));
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
