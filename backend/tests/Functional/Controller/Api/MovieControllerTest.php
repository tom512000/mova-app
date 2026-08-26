<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
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

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

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
            ['100% Wolf', 'Amélie', 'Brazil', 'Casablanca', 'Dune'],
            $this->titlesFor('')
        );
    }

    public function testTitleDescending(): void
    {
        self::assertSame(
            ['Dune', 'Casablanca', 'Brazil', 'Amélie', '100% Wolf'],
            $this->titlesFor('sort=title&direction=desc')
        );
    }

    public function testRatingSortsOnTheProfileAverageAndKeepsUnratedLastBothWays(): void
    {
        // Dune lands on 3.5: it was rewatched, scored 2 then 5.
        self::assertSame(
            ['Amélie', 'Dune', 'Brazil', '100% Wolf', 'Casablanca'],
            $this->titlesFor('sort=rating')
        );

        // The unrated film stays at the bottom even when the sort points the other way —
        // it has nothing to rank, so it is never the "lowest" note.
        self::assertSame(
            ['100% Wolf', 'Brazil', 'Dune', 'Amélie', 'Casablanca'],
            $this->titlesFor('sort=rating&direction=asc')
        );
    }

    public function testYearWatchedDateAndRuntimeSorts(): void
    {
        self::assertSame(
            ['Casablanca', 'Brazil', 'Amélie', '100% Wolf', 'Dune'],
            $this->titlesFor('sort=year')
        );

        self::assertSame(
            ['Dune', 'Brazil', 'Casablanca', 'Amélie', '100% Wolf'],
            $this->titlesFor('sort=watched')
        );

        self::assertSame(
            ['Dune', 'Brazil', 'Amélie', 'Casablanca', '100% Wolf'],
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

    public function testGenreAndYearFilters(): void
    {
        self::assertSame(['Brazil', 'Dune'], $this->titlesFor('genre='.self::SCIFI));
        self::assertSame(['Amélie'], $this->titlesFor('year=2001'));
        self::assertSame(['100% Wolf'], $this->titlesFor('genre='.self::COMEDY.'&year=2020'));
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

    public function testFacetsOnlyOfferValuesThisProfileOwns(): void
    {
        $this->client->request('GET', '/api/movies/facets');
        self::assertResponseIsSuccessful();

        $facets = $this->json();
        self::assertSame([self::COMEDY, self::SCIFI], $facets['genres']);
        self::assertSame([2021, 2020, 2001, 1985, 1942], $facets['years']);
        // JSON turns 5.0 into 5, so compare on floats rather than on what json_decode chose.
        self::assertSame([5.0, 4.5, 3.0, 2.0, 1.0], array_map('floatval', $facets['ratings']));
        self::assertTrue($facets['hasUnrated']);
    }

    /**
     * @return list<string>
     */
    private function titlesFor(string $queryString): array
    {
        $this->client->request('GET', '/api/movies?perPage=50&'.$queryString);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $item) => $item['title'], $this->json()['items']);
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

        $casablanca = $this->movie('Casablanca', 1942, 102, $comedy);
        $this->watch($user, $casablanca, '2024-02-01', null);

        $dune = $this->movie('Dune', 2021, 155, $scifi);
        $this->watch($user, $dune, '2023-12-01', 2.0);
        $this->watch($user, $dune, '2024-04-02', 5.0);

        $this->entityManager->flush();
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
