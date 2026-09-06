<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Stats;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\RetrospectiveService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A retrospective is a set of claims about a year, and most of what is pinned here is the
 * arithmetic behind the surprising ones — what a share is a share of, what "discovered"
 * excludes, and what the page says when the year is too thin to say anything.
 */
final class RetrospectiveServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RetrospectiveService $service;
    private User $user;
    private int $sequence = 960000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(RetrospectiveService::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = $this->createUser('retro@example.com');
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

    public function testOnlyTheYearAskedForIsCounted(): void
    {
        $this->watched($this->film('cette-annee'), '2026-03-04');
        $this->watched($this->film('annee-avant'), '2025-03-04');

        self::assertSame(1, $this->service->getRetrospective($this->user, 2026)->watchCount);
    }

    public function testARevisedRatingIsNotAnEveningSpentWatching(): void
    {
        // The same reading the activity calendar uses. Counting a moved rating date as a
        // viewing would credit the year with a film nobody sat through in it — and would put
        // a day on the streak with nothing behind it.
        $film = $this->film('renote');
        $watch = new Watch($this->user, $film, WatchSource::CSV_RERATING);
        $watch->setRating(4.0);
        $watch->setWatchedDate(new \DateTimeImmutable('2026-05-05'));
        $this->entityManager->persist($watch);
        $this->entityManager->flush();

        $retrospective = $this->service->getRetrospective($this->user, 2026);

        self::assertSame(0, $retrospective->watchCount);
        self::assertSame(0, $retrospective->activeDays);
    }

    public function testAGenreShareIsOfTheYearsViewingsNotOfItsGenreLabels(): void
    {
        // The bug this replaced. Summing the per-genre counts to get a denominator counts a
        // film once per label it wears, which put comedy at 32% of a thousand labels instead
        // of 78% of four hundred evenings. Here: two viewings, both comedies, one of which is
        // also a drama. Comedy is 100% of the year — not 2 out of 3 labels.
        $comedy = $this->genre('Comédie');
        $drama = $this->genre('Drame');

        $both = $this->film('les-deux', $comedy, $drama);
        $justComedy = $this->film('juste-comedie', $comedy);

        foreach ([$both, $justComedy] as $film) {
            for ($i = 0; $i < 3; ++$i) {
                $this->watched($film, sprintf('2026-06-%02d', 10 + $i));
            }
        }

        $genre = $this->service->getRetrospective($this->user, 2026)->genre;

        self::assertNotNull($genre);
        self::assertSame('Comédie', $genre->genreName);
        self::assertSame(100.0, $genre->share);
    }

    public function testTheGenreOfTheYearIsTheOneThatGainedGroundNotTheBiggest(): void
    {
        // The whole point of measuring shares. Comedy is the larger genre in both years and
        // grew in absolute terms; horror is what the year actually turned towards.
        $comedy = $this->genre('Comédie');
        $horror = $this->genre('Horreur');

        $this->watchMany($this->film('comedie-avant', $comedy), '2025-01-%02d', 8);
        $this->watchMany($this->film('horreur-avant', $horror), '2025-02-%02d', 2);

        $this->watchMany($this->film('comedie-apres', $comedy), '2026-01-%02d', 9);
        $this->watchMany($this->film('horreur-apres', $horror), '2026-02-%02d', 9);

        $genre = $this->service->getRetrospective($this->user, 2026)->genre;

        self::assertNotNull($genre);
        self::assertSame('Horreur', $genre->genreName, 'comedy grew too, horror grew as a share');
    }

    public function testAGenreSeenOnceDoesNotWinTheYear(): void
    {
        // Without a floor the block is decided by accidents: one film in a genre that had
        // none last year is an infinite rise and means nothing.
        $comedy = $this->genre('Comédie');
        $western = $this->genre('Western');

        $this->watchMany($this->film('comedie', $comedy), '2026-01-%02d', 9);
        $this->watched($this->film('western', $western), '2026-02-01');

        $genre = $this->service->getRetrospective($this->user, 2026)->genre;

        self::assertNotNull($genre);
        self::assertSame('Comédie', $genre->genreName);
    }

    public function testTheLongestStreakCarriesItsOwnDates(): void
    {
        // Three consecutive days, then a gap, then two. The claim is the run and when it was.
        foreach (['2026-04-10', '2026-04-11', '2026-04-12', '2026-04-20', '2026-04-21'] as $day) {
            $this->watched($this->film('jour-'.$day), $day);
        }

        $streak = $this->service->getRetrospective($this->user, 2026)->longestStreak;

        self::assertNotNull($streak);
        self::assertSame(3, $streak->days);
        self::assertSame('2026-04-10', $streak->startDate);
        self::assertSame('2026-04-12', $streak->endDate);
    }

    public function testAStreakIsNotCarriedInFromTheYearBefore(): void
    {
        // New Year's Eve and New Year's Day are consecutive days, but they are not the same
        // retrospective — the year's own window is what is being reported on.
        $this->watched($this->film('reveillon'), '2025-12-31');
        $this->watched($this->film('nouvel-an'), '2026-01-01');

        $streak = $this->service->getRetrospective($this->user, 2026)->longestStreak;

        self::assertNotNull($streak);
        self::assertSame(1, $streak->days);
    }

    public function testTheOldestDiscoveryIgnoresFilmsAlreadySeenInAnEarlierYear(): void
    {
        // "Discovered", so an old favourite rewatched every December does not win this block
        // every December.
        $oldFavourite = $this->film('vieux-favori');
        $oldFavourite->setReleaseYear(1950);
        $firstTime = $this->film('vraie-decouverte');
        $firstTime->setReleaseYear(1975);
        $this->entityManager->flush();

        $this->watched($oldFavourite, '2024-01-01');
        $this->watched($oldFavourite, '2026-01-01');
        $this->watched($firstTime, '2026-02-01');

        $oldest = $this->service->getRetrospective($this->user, 2026)->oldestDiscovery;

        self::assertNotNull($oldest);
        self::assertSame('vraie-decouverte', $oldest->title);
    }

    public function testTheTopListKeepsTheBestNoteGivenDuringTheYear(): void
    {
        // Not the average across rewatches: a film adored in March is not demoted by a
        // lukewarm second viewing in November.
        $film = $this->film('adore-puis-tiede');
        $this->watched($film, '2026-03-01', 5.0);
        $this->watched($film, '2026-11-01', 1.0);

        $topRated = $this->service->getRetrospective($this->user, 2026)->topRated;

        self::assertCount(1, $topRated);
        self::assertSame(5.0, $topRated[0]->rating);
    }

    public function testOnlyDirectionAndPerformanceCanBePersonOfTheYear(): void
    {
        // A producer credit is not a reason anybody picked a film, and counting it handed a
        // real library its year to an executive credited on nineteen comedies.
        $producer = $this->person('Productrice');
        $actor = $this->person('Interprète');

        for ($i = 1; $i <= 4; ++$i) {
            $film = $this->film('film-'.$i);
            $this->credit($film, $producer, CreditRole::PRODUCER);
            $this->watched($film, sprintf('2026-07-%02d', $i));
        }

        $acted = $this->film('joue');
        $this->credit($acted, $actor, CreditRole::ACTOR);
        $this->watched($acted, '2026-08-01');

        $people = $this->service->getRetrospective($this->user, 2026)->people;

        self::assertSame(['Interprète'], array_map(static fn ($p) => $p->name, $people));
    }

    public function testAFirstYearHasNothingToCompareItselfTo(): void
    {
        // "+12 viewings" against a year that does not exist would read as growth rather than
        // as a beginning.
        $this->watched($this->film('premier'), '2026-01-01');

        self::assertNull($this->service->getRetrospective($this->user, 2026)->previousYear);
    }

    public function testAYearWithNothingInItReadsAsQuietRatherThanBroken(): void
    {
        $retrospective = $this->service->getRetrospective($this->user, 2019);

        self::assertSame(0, $retrospective->watchCount);
        self::assertNull($retrospective->busiestMonth);
        self::assertNull($retrospective->longestStreak);
        self::assertNull($retrospective->genre);
        self::assertNull($retrospective->oldestDiscovery);
        self::assertSame([], $retrospective->people);
        self::assertSame([], $retrospective->topRated);
    }

    public function testTheYearListHoldsOnlyYearsWithSomethingToShow(): void
    {
        // A gap year offered in the selector would open on an empty page.
        $this->watched($this->film('a'), '2026-01-01');
        $this->watched($this->film('b'), '2023-01-01');

        self::assertSame([2026, 2023], $this->service->getAvailableYears($this->user));
    }

    public function testAnotherAccountsYearIsNotMine(): void
    {
        $other = $this->createUser('quelquun-dautre-retro@example.com');
        $this->watched($this->film('ailleurs'), '2026-01-01', user: $other);

        self::assertSame(0, $this->service->getRetrospective($this->user, 2026)->watchCount);
        self::assertSame([], $this->service->getAvailableYears($this->user));
    }

    private function watchMany(Movie $film, string $dayPattern, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->watched($film, sprintf($dayPattern, $i));
        }
    }

    private function genre(string $name): Genre
    {
        $genre = (new Genre())->setTmdbId(++$this->sequence)->setName($name);
        $this->entityManager->persist($genre);
        $this->entityManager->flush();

        return $genre;
    }

    private function person(string $name): Person
    {
        $person = (new Person())->setName($name)->setTmdbId(++$this->sequence);
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        return $person;
    }

    private function film(string $title, Genre ...$genres): Movie
    {
        $movie = new Movie('zz-retro-'.$title.'-'.(++$this->sequence), $title);
        $movie->setReleaseYear(2010);
        foreach ($genres as $genre) {
            $movie->addGenre($genre);
        }
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function credit(Movie $movie, Person $person, CreditRole $role): void
    {
        $credit = new Credit($movie, $person, $role);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
        $this->entityManager->flush();
    }

    private function watched(Movie $movie, string $day, ?float $rating = 3.0, ?User $user = null): void
    {
        $watch = new Watch($user ?? $this->user, $movie, WatchSource::CSV_IMPORT);
        $watch->setRating($rating);
        $watch->setWatchedDate(new \DateTimeImmutable($day));
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
