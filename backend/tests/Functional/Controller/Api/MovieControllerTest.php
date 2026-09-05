<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the listing's sorting and filtering over real HTTP against a real database —
 * the ordering rules live in SQL, so nothing below can be exercised with a mock.
 */
final class MovieControllerTest extends WebTestCase
{
    private const EMAIL = 'movies@example.com';
    private const PASSWORD = 'movies-password';

    /** Prefixed so they cannot collide with the globally unique genre names already stored. */
    private const COMEDY = 'ZZ-Test-Comedie';
    private const SCIFI = 'ZZ-Test-SF';
    private const PERSON = 'ZZ Test Personne';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** Directs Brazil and Dune, and acts in Amelie - enough to tell the roles apart. */
    private string $personId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->seedLibrary();
        $this->login();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDefaultsToTitleAscending(): void
    {
        self::assertSame(
            ['100% Wolf', 'Amélie', 'Brazil', 'Casablanca', 'Dune', 'Zone Blanche'],
            $this->titlesFor('')
        );
    }

    public function testTitleDescending(): void
    {
        self::assertSame(
            ['Zone Blanche', 'Dune', 'Casablanca', 'Brazil', 'Amélie', '100% Wolf'],
            $this->titlesFor('sort=title&direction=desc')
        );
    }

    public function testRatingSortsOnTheProfileAverageAndKeepsUnratedLastBothWays(): void
    {
        // Dune lands on 3.5: it was rewatched, scored 2 then 5.
        self::assertSame(
            ['Amélie', 'Zone Blanche', 'Dune', 'Brazil', '100% Wolf', 'Casablanca'],
            $this->titlesFor('sort=rating')
        );

        // The unrated film stays at the bottom even when the sort points the other way —
        // it has nothing to rank, so it is never the "lowest" note.
        self::assertSame(
            ['100% Wolf', 'Brazil', 'Dune', 'Zone Blanche', 'Amélie', 'Casablanca'],
            $this->titlesFor('sort=rating&direction=asc')
        );
    }

    public function testYearWatchedDateAndRuntimeSorts(): void
    {
        self::assertSame(
            ['Casablanca', 'Brazil', 'Amélie', '100% Wolf', 'Dune', 'Zone Blanche'],
            $this->titlesFor('sort=year')
        );

        self::assertSame(
            ['Zone Blanche', 'Dune', 'Brazil', 'Casablanca', 'Amélie', '100% Wolf'],
            $this->titlesFor('sort=watched')
        );

        self::assertSame(
            ['Zone Blanche', 'Dune', 'Brazil', 'Amélie', 'Casablanca', '100% Wolf'],
            $this->titlesFor('sort=runtime')
        );
    }

    public function testRatingFilterMatchesAWatchRatherThanTheAverage(): void
    {
        self::assertSame(['Amélie'], $this->titlesFor('rating=4.5'));

        // Dune was scored 2 then 5 on a rewatch, so it answers to both of those notes...
        self::assertSame(['Dune'], $this->titlesFor('rating=2'));
        self::assertSame(['Dune'], $this->titlesFor('rating=5'));

        // ...but not to the 3.5 average its card displays, which it was never given. The
        // facet list only offers notes that were actually awarded, so no film ends up
        // unreachable this way — whereas filtering on the average would hide any film
        // whose rewatches land between two half-stars.
        self::assertSame([], $this->titlesFor('rating=3.5'));
    }

    public function testUnratedFilter(): void
    {
        self::assertSame(['Casablanca'], $this->titlesFor('rating=none'));
    }

    public function testMediaTypeFilterSeparatesFilmsFromSeries(): void
    {
        self::assertSame(
            ['100% Wolf', 'Amélie', 'Brazil', 'Casablanca', 'Dune'],
            $this->titlesFor('mediaType=movie')
        );
        self::assertSame(['Zone Blanche'], $this->titlesFor('mediaType=series'));
    }

    public function testAnAbsentOrUnknownMediaTypeBrowsesTheWholeLibrary(): void
    {
        // Same forgiving reading as every other filter: a stale bookmark shows everything
        // rather than an error page.
        self::assertSame($this->titlesFor(''), $this->titlesFor('mediaType=nonsense'));
        self::assertContains('Zone Blanche', $this->titlesFor(''));
    }

    public function testTheMediaTypeFilterCombinesWithTheOtherFilters(): void
    {
        // The series carries the comedy genre too, so this proves the two narrow together
        // rather than one quietly winning.
        self::assertSame(
            ['100% Wolf', 'Amélie', 'Casablanca'],
            $this->titlesFor('genre='.self::COMEDY.'&mediaType=movie')
        );
    }

    public function testACardSaysWhichKindItIs(): void
    {
        $this->titlesFor('mediaType=series');
        $card = $this->json()['items'][0];

        self::assertSame('series', $card['mediaType']);
        self::assertSame('movie', $this->firstCardFor('mediaType=movie')['mediaType']);
    }

    public function testASeriesDetailCarriesItsSeasonsAndEpisodes(): void
    {
        $id = $this->firstCardFor('mediaType=series')['id'];

        $this->client->request('GET', "/api/movies/{$id}");
        self::assertResponseIsSuccessful();

        $detail = $this->json();
        self::assertSame('series', $detail['mediaType']);
        self::assertSame(2, $detail['seasonCount']);
        self::assertSame(12, $detail['episodeCount']);
        // The whole run, not one episode — what the detail page turns into "10 h 15".
        self::assertSame(615, $detail['runtimeMinutes']);
    }

    public function testASeriesIsCreditedToItsCreatorAndToNoDirector(): void
    {
        $id = $this->firstCardFor('mediaType=series')['id'];

        $this->client->request('GET', "/api/movies/{$id}");
        $detail = $this->json();

        self::assertSame([self::PERSON], array_column($detail['creators'], 'name'));
        // A series has no director of record: TMDB keeps episode directors in a payload this
        // app never fetches. Filing the creator here instead, as it did at first, is what put
        // whoever made a series into the most-watched directors ranking.
        self::assertSame([], $detail['directors']);
    }

    public function testAFilmIsCreditedToItsDirectorAndToNoCreator(): void
    {
        $id = $this->firstCardFor('mediaType=movie&q=Brazil')['id'];

        $this->client->request('GET', "/api/movies/{$id}");
        $detail = $this->json();

        self::assertSame([self::PERSON], array_column($detail['directors'], 'name'));
        self::assertSame([], $detail['creators'], 'a film has nobody who created it');
    }

    public function testCreatingASeriesDoesNotCountTowardsDirecting(): void
    {
        // The person directed two films and created one series. The ranking of directors
        // must say two.
        $this->client->request('GET', '/api/stats/directors');
        self::assertResponseIsSuccessful();

        $row = array_values(array_filter($this->json(), static fn (array $r) => self::PERSON === $r['name']));
        self::assertCount(1, $row);
        self::assertSame(2, $row[0]['movieCount']);
    }

    public function testAFilmDetailListsItsViewingsOldestFirst(): void
    {
        // Written newest first on purpose, so a listing that simply echoed insertion order
        // would fail here.
        //
        // This pins the contract, not its mechanism: removing the mapping's ORDER BY does
        // not make it fail, because Postgres returns these few rows in date order regardless.
        // That is why the film page sorts the list again before reading it in pairs instead
        // of relying on what arrives.
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $comedy = $this->entityManager->getRepository(Genre::class)->findOneBy(['name' => self::COMEDY]);
        self::assertNotNull($user);
        self::assertNotNull($comedy);

        $film = $this->movie('ZZ Ordre Des Visionnages', 2019, 100, $comedy);
        $this->watch($user, $film, '2024-06-04', 5.0);
        $this->watch($user, $film, '2021-02-17', 3.0);
        $this->watch($user, $film, '2022-11-30', 4.0);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/movies?q=ZZ+Ordre');
        $id = $this->json()['items'][0]['id'];

        $this->client->request('GET', '/api/movies/'.$id);
        $watches = $this->json()['watches'];

        self::assertSame(['2021-02-17', '2022-11-30', '2024-06-04'], array_column($watches, 'watchedDate'));
        // floatval, because JSON brings a whole 5.0 back as the integer 5.
        self::assertSame([3.0, 4.0, 5.0], array_map('floatval', array_column($watches, 'rating')));
    }

    public function testAFilmDetailLeavesTheSeriesFieldsEmpty(): void
    {
        $id = $this->firstCardFor('mediaType=movie')['id'];

        $this->client->request('GET', "/api/movies/{$id}");
        self::assertResponseIsSuccessful();

        $detail = $this->json();
        self::assertSame('movie', $detail['mediaType']);
        // Null rather than 0 or 1: a film has no seasons, it does not have one season.
        self::assertNull($detail['seasonCount']);
        self::assertNull($detail['episodeCount']);
        self::assertNull($detail['lastAirDate']);
    }

    public function testTheWallCanBeNarrowedToOneKind(): void
    {
        self::assertSame(
            ['Zone Blanche'],
            array_column($this->wall('mediaType=series')['items'], 'title')
        );
        self::assertSame(
            ['Amélie', 'Brazil', 'Dune'],
            array_column($this->wall('sort=title&direction=asc&mediaType=movie')['items'], 'title')
        );
    }

    public function testGenreAndYearFilters(): void
    {
        self::assertSame(['Brazil', 'Dune'], $this->titlesFor('genre='.self::SCIFI));
        self::assertSame(['Amélie'], $this->titlesFor('year=2001'));
        self::assertSame(['100% Wolf'], $this->titlesFor('genre='.self::COMEDY.'&year=2020'));
    }

    public function testTheWatchedOnFilterAnswersForOneDayOfTheCalendar(): void
    {
        self::assertSame(['Amélie'], $this->titlesFor('watchedOn=2024-01-05'));

        // Dune was watched twice, and both of its squares lead back to it — the calendar
        // counted the rewatch on its own day, so that day has to be able to name it.
        self::assertSame(['Dune'], $this->titlesFor('watchedOn=2023-12-01'));
        self::assertSame(['Dune'], $this->titlesFor('watchedOn=2024-04-02'));

        // A day nothing was watched on is empty rather than unfiltered. Only squares that
        // counted something are clickable, but the address bar is not bound by that.
        self::assertSame([], $this->titlesFor('watchedOn=2024-01-06'));

        self::assertSame(['Dune'], $this->titlesFor('watchedOn=2024-04-02&genre='.self::SCIFI));
        self::assertSame([], $this->titlesFor('watchedOn=2024-04-02&genre='.self::COMEDY));
    }

    public function testAnUnusableWatchedOnIsNoFilterAtAllRatherThanAnEmptyLibrary(): void
    {
        $whole = $this->titlesFor('');

        self::assertSame($whole, $this->titlesFor('watchedOn=hier'));
        self::assertSame($whole, $this->titlesFor('watchedOn=2024-1-5'));
        // Parses without complaint and matches nothing, which would read as an empty
        // library instead of as the bad date it is.
        self::assertSame($whole, $this->titlesFor('watchedOn=2026-02-30'));
    }

    public function testSearchMatchesTheOriginalTitleAndTreatsWildcardsAsLiterals(): void
    {
        self::assertSame(['Amélie'], $this->titlesFor('q=fabuleux'));

        // A bare % must find the film whose title contains one, not every film.
        self::assertSame(['100% Wolf'], $this->titlesFor('q=%25'));
    }

    public function testUnknownSortFallsBackToTheDefaultInsteadOfFailing(): void
    {
        self::assertSame($this->titlesFor(''), $this->titlesFor('sort=nonsense&direction=sideways'));
    }

    public function testRandomIsStablePerSeedAndPagesWithoutRepeating(): void
    {
        $first = $this->titlesFor('sort=random&seed=alpha');
        self::assertSame($first, $this->titlesFor('sort=random&seed=alpha'));

        $paged = array_merge(
            $this->titlesFor('sort=random&seed=alpha&perPage=2&page=1'),
            $this->titlesFor('sort=random&seed=alpha&perPage=2&page=2'),
            $this->titlesFor('sort=random&seed=alpha&perPage=2&page=3'),
        );
        self::assertSame($first, $paged);
    }

    public function testPersonFilterNarrowsToOneCreditRole(): void
    {
        self::assertSame(['Brazil', 'Dune'], $this->titlesFor("personId={$this->personId}&personRole=director"));
        self::assertSame(['Zone Blanche'], $this->titlesFor("personId={$this->personId}&personRole=creator"));
        self::assertSame(['Amélie'], $this->titlesFor("personId={$this->personId}&personRole=actor"));
        self::assertSame([], $this->titlesFor("personId={$this->personId}&personRole=writer"));

        // Directing and creating are separate credits, so the series is absent from the
        // director list above and present here — the whole point of the two roles.
        self::assertSame(
            ['Amélie', 'Brazil', 'Dune', 'Zone Blanche'],
            $this->titlesFor("personId={$this->personId}"),
            'without a role the same person answers for every credit they hold'
        );
    }

    public function testPersonFilterCombinesWithTheOtherFiltersAndSorts(): void
    {
        self::assertSame(
            ['Brazil'],
            $this->titlesFor("personId={$this->personId}&personRole=director&year=1985")
        );
        self::assertSame(
            ['Dune', 'Brazil'],
            $this->titlesFor("personId={$this->personId}&personRole=director&sort=year&direction=desc")
        );
    }

    public function testTheListingNamesThePersonItWasFilteredOn(): void
    {
        $this->titlesFor("personId={$this->personId}&personRole=director");
        self::assertSame(
            ['id' => $this->personId, 'name' => self::PERSON, 'role' => 'director'],
            $this->json()['person']
        );

        // No person filter, nothing to label.
        $this->titlesFor('');
        self::assertNull($this->json()['person']);

        // An id nobody matches narrows to nothing rather than silently listing everything.
        // A well-formed UUID nobody holds, so the filter narrows to nothing rather
        // than being ignored — which is what a malformed one would do.
        self::assertSame([], $this->titlesFor('personId=01920000-0000-7000-8000-000000000000'));
        self::assertNull($this->json()['person']);
    }

    public function testFacetsOnlyOfferValuesThisProfileOwns(): void
    {
        $this->client->request('GET', '/api/movies/facets');
        self::assertResponseIsSuccessful();

        $facets = $this->json();
        self::assertSame([self::COMEDY, self::SCIFI], $facets['genres']);
        self::assertSame([2022, 2021, 2020, 2001, 1985, 1942], $facets['years']);
        // JSON turns 5.0 into 5, so compare on floats rather than on what json_decode chose.
        self::assertSame([5.0, 4.5, 4.0, 3.0, 2.0, 1.0], array_map('floatval', $facets['ratings']));
        self::assertTrue($facets['hasUnrated']);
    }

    /**
     * @return list<string>
     */
    public function testTheMuseumWallHangsOnlyWhatHasArtwork(): void
    {
        $payload = $this->wall('sort=title&direction=asc');

        // "100% Wolf" and "Casablanca" have no poster: nothing to hang.
        self::assertSame(['Amélie', 'Brazil', 'Dune', 'Zone Blanche'], array_column($payload['items'], 'title'));
        self::assertSame(4, $payload['total']);
    }

    public function testTheWallComesBackWhole(): void
    {
        // A page boundary in the middle of a wall would be a wall you cannot walk past, so
        // the paging parameters the listing honours are deliberately ignored here.
        self::assertCount(4, $this->wall('perPage=1&page=2')['items']);
    }

    public function testAnExhibitCarriesOnlyWhatItNeedsToHang(): void
    {
        $first = $this->wall('sort=title&direction=asc')['items'][0];

        // mediaType earns its place despite the cost: the wall labels a series as one, and
        // the alternative would be a second request per exhibit to find out.
        self::assertSame(
            ['id', 'title', 'releaseYear', 'posterUrl', 'myAverageRating', 'mediaType'],
            array_keys($first)
        );
        // A wall holds dozens at once, so it asks TMDB for the thumbnail, not the card size.
        self::assertStringContainsString('/w185/amelie.jpg', $first['posterUrl']);
        self::assertSame(4.5, $first['myAverageRating']);
    }

    public function testTheWallIsHungInTheOrderItWasAskedFor(): void
    {
        self::assertSame(
            ['Zone Blanche', 'Dune', 'Amélie', 'Brazil'],
            array_column($this->wall('sort=year&direction=desc')['items'], 'title')
        );

        // Dune was watched twice, at 2 and at 5: the wall shows the same average the cards
        // do. Looked up by title rather than by position, so a change of ordering above
        // cannot quietly turn this into an assertion about a different exhibit.
        $exhibits = array_column($this->wall('sort=year&direction=desc')['items'], null, 'title');
        self::assertSame(3.5, $exhibits['Dune']['myAverageRating']);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function wall(string $queryString): array
    {
        $this->client->request('GET', '/api/movies/posters?'.$queryString);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function titlesFor(string $queryString): array
    {
        $this->client->request('GET', '/api/movies?perPage=50&'.$queryString);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $item) => $item['title'], $this->json()['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstCardFor(string $queryString): array
    {
        $this->titlesFor($queryString);

        return $this->json()['items'][0];
    }

    private function seedLibrary(): void
    {
        $user = new User(self::EMAIL, 'Movies');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);

        $comedy = $this->genre(self::COMEDY);
        $scifi = $this->genre(self::SCIFI);

        $wolf = $this->movie('100% Wolf', 2020, 96, $comedy);
        $this->watch($user, $wolf, '2023-11-01', 1.0);

        $amelie = $this->movie('Amélie', 2001, 122, $comedy);
        $amelie->setOriginalTitle('Le Fabuleux Destin d\'Amélie Poulain');
        $this->watch($user, $amelie, '2024-01-05', 4.5);

        $brazil = $this->movie('Brazil', 1985, 132, $scifi);
        $this->watch($user, $brazil, '2024-03-10', 3.0);

        // Only some of the library has artwork, so the museum wall has something to exclude.
        $amelie->setPosterPath('/amelie.jpg');
        $brazil->setPosterPath('/brazil.jpg');

        $casablanca = $this->movie('Casablanca', 1942, 102, $comedy);
        $this->watch($user, $casablanca, '2024-02-01', null);

        $dune = $this->movie('Dune', 2021, 155, $scifi);
        $dune->setPosterPath('/dune.jpg');
        $this->watch($user, $dune, '2023-12-01', 2.0);
        $this->watch($user, $dune, '2024-04-02', 5.0);

        // A series, sitting in the same library as the films because that is exactly how
        // Letterboxd exports it. Its runtime is the whole run rather than one episode,
        // which is why it outranks every film on `sort=runtime` below — the ordering is
        // right, and it is also why OverviewStatsService excludes series from "le plus long".
        $zone = $this->movie('Zone Blanche', 2022, 615, $comedy);
        $zone->setMediaType(MediaType::SERIES);
        $zone->setSeasonCount(2);
        $zone->setEpisodeCount(12);
        $zone->setPosterPath('/zone.jpg');
        $this->watch($user, $zone, '2024-05-01', 4.0);

        $person = (new Person())->setName(self::PERSON);
        $this->entityManager->persist($person);
        $this->credit($brazil, $person, CreditRole::DIRECTOR);
        $this->credit($dune, $person, CreditRole::DIRECTOR);
        $this->credit($amelie, $person, CreditRole::ACTOR);
        // The same person creates the series. That is the shape the roles have to keep
        // apart: creating Zone Blanche must not add to their tally as a director.
        $this->credit($zone, $person, CreditRole::CREATOR);

        $this->entityManager->flush();

        $this->personId = (string) $person->getId();
    }

    private function credit(Movie $movie, Person $person, CreditRole $role): void
    {
        $credit = new Credit($movie, $person, $role);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
    }

    private function genre(string $name): Genre
    {
        $genre = (new Genre())->setName($name);
        $this->entityManager->persist($genre);

        return $genre;
    }

    private function movie(string $title, int $year, int $runtime, Genre $genre): Movie
    {
        $movie = new Movie('test-'.md5($title), $title);
        $movie->setReleaseYear($year);
        $movie->setRuntimeMinutes($runtime);
        $movie->addGenre($genre);
        $this->entityManager->persist($movie);

        return $movie;
    }

    private function watch(User $user, Movie $movie, string $date, ?float $rating): void
    {
        $watch = new Watch($user, $movie, WatchSource::MANUAL);
        $watch->setWatchedDate(new \DateTimeImmutable($date));
        $watch->setRating($rating);
        $this->entityManager->persist($watch);
    }

    private function login(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD])
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
