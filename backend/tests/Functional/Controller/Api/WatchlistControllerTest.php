<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Enum\MediaType;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\WatchlistEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The watchlist is the page that answers "what am I watching tonight", so every test here is
 * a version of that question: what fits in the time left, what mood, which era, and what has
 * been sitting there longest.
 *
 * The fixture is small and deliberately awkward: a film with no runtime at all, a series long
 * enough that no evening holds it, and four entries added months apart.
 */
final class WatchlistControllerTest extends WebTestCase
{
    private const EMAIL = 'watchlist@example.com';
    private const PASSWORD = 'watchlist-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->seed();
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

    public function testTheWholeWatchlistComesBackNewestAdditionFirst(): void
    {
        self::assertSame(
            ['ZZ Serie Fleuve', 'ZZ Court', 'ZZ Long', 'ZZ Sans Duree'],
            $this->titles($this->get('/api/watchlist'))
        );
    }

    public function testATimeBudgetKeepsOnlyWhatFitsInsideIt(): void
    {
        $body = $this->get('/api/watchlist?maxRuntime=90');

        // ZZ Long runs 140 minutes and the series 600; neither fits an hour and a half. And
        // ZZ Sans Duree is left out too: an unknown length cannot answer the question asked.
        self::assertSame(['ZZ Court'], $this->titles($body));
        self::assertSame(1, $body['total']);
    }

    public function testAWiderBudgetLetsTheLongerFilmBackIn(): void
    {
        self::assertSame(
            ['ZZ Court', 'ZZ Long'],
            $this->titles($this->get('/api/watchlist?maxRuntime=150&sort=runtime&direction=asc'))
        );
    }

    public function testTheGenreFilterNarrowsToThatGenre(): void
    {
        self::assertSame(['ZZ Court'], $this->titles($this->get('/api/watchlist?genre=ZZ-Watchlist-Genre')));
    }

    public function testTheDecadeFilterCoversItsTenYears(): void
    {
        self::assertSame(
            ['ZZ Court', 'ZZ Long'],
            $this->titles($this->get('/api/watchlist?decade=1990&sort=title&direction=asc'))
        );
        self::assertSame([], $this->titles($this->get('/api/watchlist?decade=1970')));
    }

    public function testWhatHasBeenWaitingLongestComesFirstInAscendingOrder(): void
    {
        $titles = $this->titles($this->get('/api/watchlist?sort=added&direction=asc'));

        self::assertSame('ZZ Sans Duree', $titles[0], 'added first, so waiting the longest');
    }

    public function testTheFacetsDescribeTheWatchlistAndNotTheLibrary(): void
    {
        $facets = $this->get('/api/watchlist/facets');

        self::assertSame(['ZZ-Serie-Genre', 'ZZ-Watchlist-Genre'], $facets['genres']);
        // 2021, 2001 and the two nineties films — three decades, newest first.
        self::assertSame([2020, 2000, 1990], $facets['decades']);
        self::assertSame(45, $facets['shortestRuntime']);
        self::assertSame(600, $facets['longestRuntime']);
    }

    public function testTheDrawAnswersWithSomethingThatMatchesTheFilters(): void
    {
        // Only one entry fits, so the draw has no choice and the assertion can be exact.
        $body = $this->get('/api/watchlist/pick?maxRuntime=90');

        self::assertNotNull($body['movie']);
        self::assertSame('ZZ Court', $body['movie']['title']);
        // The runtime rides along: the whole point was fitting the evening.
        self::assertSame(45, $body['movie']['runtimeMinutes']);
    }

    public function testTheDrawComesBackEmptyRatherThanIgnoringTheFilters(): void
    {
        // Nothing is that short. Answering anything at all would be worse than answering
        // nothing, because the answer would not fit the evening it was asked about.
        self::assertNull($this->get('/api/watchlist/pick?maxRuntime=10')['movie']);
    }

    public function testTheDrawNeverReachesOutsideTheWatchlist(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; ++$i) {
            $seen[] = $this->get('/api/watchlist/pick')['movie']['title'];
        }

        self::assertEmpty(array_diff(
            array_unique($seen),
            ['ZZ Court', 'ZZ Long', 'ZZ Sans Duree', 'ZZ Serie Fleuve']
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function titles(array $body): array
    {
        return array_map(static fn (array $item) => $item['title'], $body['items']);
    }

    private function seed(): void
    {
        $this->user = new User(self::EMAIL, 'Watchlist');
        $this->user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::PASSWORD)
        );
        $this->entityManager->persist($this->user);

        $genre = (new Genre())->setName('ZZ-Watchlist-Genre');
        $seriesGenre = (new Genre())->setName('ZZ-Serie-Genre');
        $this->entityManager->persist($genre);
        $this->entityManager->persist($seriesGenre);

        $short = $this->movie('ZZ Court', 1999, 45);
        $short->addGenre($genre);
        $long = $this->movie('ZZ Long', 1995, 140);
        $undated = $this->movie('ZZ Sans Duree', 2001, null);

        $series = $this->movie('ZZ Serie Fleuve', 2021, 600);
        $series->setMediaType(MediaType::SERIES);
        $series->addGenre($seriesGenre);

        // Added months apart, so "what has been waiting longest" has a right answer.
        $this->addToWatchlist($undated, '2024-01-05');
        $this->addToWatchlist($long, '2024-06-01');
        $this->addToWatchlist($short, '2024-09-01');
        $this->addToWatchlist($series, '2025-02-01');

        // A film outside the watchlist entirely: the draw must never reach it.
        $this->movie('ZZ Hors Watchlist', 1999, 60);

        $this->entityManager->flush();
    }

    private function movie(string $title, ?int $year, ?int $runtime): Movie
    {
        $movie = new Movie('zz-wl-'.strtolower(str_replace(' ', '-', $title)), $title);
        $movie->setReleaseYear($year)->setRuntimeMinutes($runtime);
        $this->entityManager->persist($movie);

        return $movie;
    }

    private function addToWatchlist(Movie $movie, string $addedDate): void
    {
        $entry = new WatchlistEntry($this->user, $movie);
        $entry->setAddedDate(new \DateTimeImmutable($addedDate));
        $this->entityManager->persist($entry);
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
}
