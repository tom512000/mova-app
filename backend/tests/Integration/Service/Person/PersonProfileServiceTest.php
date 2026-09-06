<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Person;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\WatchlistEntry;
use App\Service\Person\PersonProfileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The page's whole reason for existing is that one person is one person, however many jobs
 * they hold — so most of what is pinned here is about not counting somebody twice.
 */
final class PersonProfileServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PersonProfileService $service;
    private User $user;
    private int $tmdbId = 980000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(PersonProfileService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('fiche-personne@example.com');
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

    public function testAFilmDirectedAndActedInByTheSamePersonIsOneWork(): void
    {
        // The bug the page replaces: three credits used to be three links to three lists,
        // and a naive join would report three films.
        $person = $this->person('Triple casquette');
        $film = $this->film('trois-casquettes');
        $this->credit($film, $person, CreditRole::DIRECTOR);
        $this->credit($film, $person, CreditRole::WRITER);
        $this->credit($film, $person, CreditRole::ACTOR, 'Lui-même');
        $this->watched($film, 4.0);

        $profile = $this->service->getProfile($person, $this->user);

        self::assertCount(1, $profile->works);
        self::assertSame(1, $profile->watchedCount);
        self::assertSame(
            [CreditRole::DIRECTOR, CreditRole::WRITER, CreditRole::ACTOR],
            $profile->works[0]->roles,
            'listed in credit-block order, direction first'
        );
    }

    public function testEachJobIsCountedAndScoredOnItsOwn(): void
    {
        // The reason the roles are not folded into one average: the same person can be
        // watched a lot in one job and rated quite differently in another.
        $person = $this->person('Deux métiers');

        $directed = $this->film('realise');
        $this->credit($directed, $person, CreditRole::DIRECTOR);
        $this->watched($directed, 5.0);

        foreach (['joue-un' => 2.0, 'joue-deux' => 3.0] as $slug => $rating) {
            $acted = $this->film($slug);
            $this->credit($acted, $person, CreditRole::ACTOR);
            $this->watched($acted, $rating);
        }

        $profile = $this->service->getProfile($person, $this->user);

        self::assertSame(CreditRole::ACTOR, $profile->roles[0]->role, 'the most-watched job leads');
        self::assertSame(2, $profile->roles[0]->watchedCount);
        self::assertSame(2.5, $profile->roles[0]->averageRating);
        self::assertSame(CreditRole::DIRECTOR, $profile->roles[1]->role);
        self::assertSame(5.0, $profile->roles[1]->averageRating);
    }

    public function testTheOverallAverageWeighsEachWorkOnce(): void
    {
        // Not the average of the per-role averages, which would count the triple-credited
        // film three times, and not the average of the watch rows either.
        $person = $this->person('Moyenne');

        $both = $this->film('les-deux');
        $this->credit($both, $person, CreditRole::DIRECTOR);
        $this->credit($both, $person, CreditRole::ACTOR);
        $this->watched($both, 1.0);

        $acted = $this->film('juste-joue');
        $this->credit($acted, $person, CreditRole::ACTOR);
        $this->watched($acted, 4.0);

        $profile = $this->service->getProfile($person, $this->user);

        self::assertSame(2, $profile->watchedCount);
        self::assertSame(2.5, $profile->averageRating);
    }

    public function testAWorkCreditedButNeverWatchedIsListedWithoutBeingCounted(): void
    {
        // A person's page is largely about what is left to see of them, so the unwatched
        // work has to appear — but it must not dilute the average or the tally.
        $person = $this->person('Pas fini');

        $seen = $this->film('vu');
        $this->credit($seen, $person, CreditRole::DIRECTOR);
        $this->watched($seen, 4.0);

        $unseen = $this->film('pas-vu');
        $this->credit($unseen, $person, CreditRole::DIRECTOR);

        $profile = $this->service->getProfile($person, $this->user);

        self::assertCount(2, $profile->works);
        self::assertSame(1, $profile->watchedCount);
        self::assertSame(4.0, $profile->averageRating);
        self::assertSame(1, $profile->roles[0]->watchedCount);
        self::assertSame(1, $profile->roles[0]->unwatchedCount);
    }

    public function testAWorkInTheWatchlistIsFlaggedAsSuch(): void
    {
        $person = $this->person('À voir');
        $film = $this->film('en-attente');
        $this->credit($film, $person, CreditRole::ACTOR);
        $this->entityManager->persist(new WatchlistEntry($this->user, $film));
        $this->entityManager->flush();

        $profile = $this->service->getProfile($person, $this->user);

        self::assertSame(1, $profile->watchlistCount);
        self::assertTrue($profile->works[0]->inWatchlist);
        self::assertFalse($profile->works[0]->watched);
    }

    public function testARevisedRatingCountsTowardsTheScoreButNotTowardsTheDate(): void
    {
        // The same reading the museum wall was taught: revising a note months later is a
        // real rating, but it is not an evening spent in front of the film.
        $person = $this->person('Renoté');
        $film = $this->film('renote');
        $this->credit($film, $person, CreditRole::DIRECTOR);

        $watched = new Watch($this->user, $film, WatchSource::CSV_IMPORT);
        $watched->setRating(2.0);
        $watched->setWatchedDate(new \DateTimeImmutable('2020-01-01'));
        $this->entityManager->persist($watched);

        $revised = new Watch($this->user, $film, WatchSource::CSV_RERATING);
        $revised->setRating(4.0);
        $revised->setWatchedDate(new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($revised);
        $this->entityManager->flush();

        $profile = $this->service->getProfile($person, $this->user);

        self::assertSame(3.0, $profile->works[0]->myAverageRating, 'both notes weigh on the score');
        self::assertSame('2020-01-01', $profile->works[0]->lastWatchedDate);
    }

    public function testTheGapComparesThisPersonToTheRestOfTheLibrary(): void
    {
        $person = $this->person('Au-dessus');
        $theirs = $this->film('le-sien');
        $this->credit($theirs, $person, CreditRole::DIRECTOR);
        $this->watched($theirs, 5.0);

        // Nothing to do with them, but it moves the library average the gap is read against.
        $this->watched($this->film('ailleurs'), 3.0);

        $profile = $this->service->getProfile($person, $this->user);

        self::assertSame(5.0, $profile->averageRating);
        self::assertSame(1.0, $profile->ratingGap, '5 against a library averaging 4');
    }

    public function testARewatchedFilmDoesNotWeighTwiceOnTheLibraryAverage(): void
    {
        // Both sides of the subtraction have to be averaged the same way. Reading the
        // library average straight off the watch rows would let a film watched three times
        // pull the reference down three times over, and the gap would then say more about
        // rewatching habits than about the person.
        $person = $this->person('Comparé');
        $theirs = $this->film('le-sien-bis');
        $this->credit($theirs, $person, CreditRole::DIRECTOR);
        $this->watched($theirs, 4.0);

        // One other film, rated 2, but watched three times over.
        $rewatched = $this->film('revu-souvent');
        $this->watched($rewatched, 2.0);
        $this->watched($rewatched, 2.0);
        $this->watched($rewatched, 2.0);

        $profile = $this->service->getProfile($person, $this->user);

        // Per work: (4 + 2) / 2 = 3, so the gap is +1. Per watch row it would be
        // (4 + 2 + 2 + 2) / 4 = 2.5, and the gap would come out at +1.5.
        self::assertSame(1.0, $profile->ratingGap);
    }

    public function testAnotherAccountsViewingsAreNotMine(): void
    {
        $other = $this->createUser('quelquun-dautre-personne@example.com');
        $person = $this->person('Vu ailleurs');
        $film = $this->film('chez-lautre');
        $this->credit($film, $person, CreditRole::ACTOR);
        $this->watched($film, 5.0, user: $other);

        $profile = $this->service->getProfile($person, $this->user);

        self::assertCount(1, $profile->works, 'the work is still theirs');
        self::assertFalse($profile->works[0]->watched);
        self::assertSame(0, $profile->watchedCount);
        self::assertNull($profile->averageRating);
        self::assertNull($profile->ratingGap);
    }

    private function person(string $name): Person
    {
        $person = (new Person())->setName($name)->setTmdbId(++$this->tmdbId);
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        return $person;
    }

    private function film(string $title): Movie
    {
        $movie = new Movie('zz-personne-'.$title, $title);
        $movie->setReleaseYear(2010);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function credit(Movie $movie, Person $person, CreditRole $role, ?string $character = null): void
    {
        $credit = new Credit($movie, $person, $role);
        $credit->setCharacterName($character);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
        $this->entityManager->flush();
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
