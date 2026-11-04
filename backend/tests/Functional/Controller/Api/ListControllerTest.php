<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\MovieList;
use App\Entity\MovieListEntry;
use App\Entity\ProfileAccess;
use App\Entity\User;
use App\Entity\Watch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The lists, over real HTTP. One list of four works — three films and a series, two of them
 * watched, one of those twice — read against the owner's library.
 */
final class ListControllerTest extends WebTestCase
{
    private const EMAIL = 'lists@example.com';
    private const PASSWORD = 'lists-password';
    private const GENRE = 'ZZ-Liste-Genre';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $owner;
    private MovieList $list;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->owner = $this->user(self::EMAIL, self::PASSWORD);
        $this->seedList();
        $this->login(self::EMAIL, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheIndexCountsWhatHasBeenSeen(): void
    {
        $this->client->request('GET', '/api/lists');
        self::assertResponseIsSuccessful();

        $lists = $this->json();
        self::assertCount(1, $lists);
        self::assertSame('Mon top', $lists[0]['name']);
        self::assertSame(4, $lists[0]['workCount']);
        // The film watched twice is one work seen, not two.
        self::assertSame(2, $lists[0]['watchedCount']);
        self::assertSame(['classique'], $lists[0]['tags']);
    }

    public function testTheCoverFollowsTheListsOrderAndSkipsMissingArtwork(): void
    {
        $this->client->request('GET', '/api/lists');
        $covers = $this->json()[0]['posterUrls'];

        // Four works, three posters: the third on the list has none and is left out rather
        // than drawn as a gap.
        self::assertCount(3, $covers);
        self::assertStringEndsWith('/zz-one.jpg', $covers[0]);
        self::assertStringEndsWith('/zz-two.jpg', $covers[1]);
        self::assertStringEndsWith('/zz-four.jpg', $covers[2]);
    }

    public function testTheHeaderSaysWhatIsLeft(): void
    {
        $this->client->request('GET', "/api/lists/{$this->list->getId()}");
        self::assertResponseIsSuccessful();

        $detail = $this->json();
        self::assertSame(3, $detail['movieCount']);
        self::assertSame(1, $detail['seriesCount']);
        self::assertSame(2, $detail['watchedCount']);
        // Films only, unwatched only: the third film's 100 minutes. The unwatched series is
        // left out — its runtime is the whole show — and so is everything already seen.
        self::assertSame(100, $detail['remainingRuntimeMinutes']);
        // Per work: (4 + 3) / 2 for the film rated 4 then 3 is 3.5, then (3.5 + 2) / 2.
        self::assertEqualsWithDelta(2.75, $detail['averageRating'], 0.001);
        self::assertSame([self::GENRE], $detail['genres']);
    }

    public function testTheEntriesComeInTheListsOwnOrderByDefault(): void
    {
        self::assertSame(['Un', 'Deux', 'Trois', 'Quatre'], $this->titles());
        self::assertSame([1, 2, 3, 4], array_column($this->json()['items'], 'position'));
    }

    public function testTheEntriesCanBeNarrowedToWhatIsLeftToSee(): void
    {
        self::assertSame(['Trois', 'Quatre'], $this->titles(['status' => 'unwatched']));
        self::assertSame(['Un', 'Deux'], $this->titles(['status' => 'watched']));
        self::assertSame(['Quatre'], $this->titles(['mediaType' => 'series']));
        self::assertSame(['Deux'], $this->titles(['genre' => self::GENRE]));
    }

    public function testSortingByRatingLeavesTheUnseenAtTheBottom(): void
    {
        // Descending by default; nothing seen has no note, and NULLS LAST keeps it out of
        // the way whichever direction the sort points.
        self::assertSame(['Un', 'Deux', 'Trois', 'Quatre'], $this->titles(['sort' => 'rating']));
        self::assertSame(['Deux', 'Un', 'Trois', 'Quatre'], $this->titles(['sort' => 'rating', 'direction' => 'asc']));
    }

    public function testSomebodyElsesListIsNotFound(): void
    {
        $this->user('lists-stranger@example.com', 'stranger-password');
        $this->login('lists-stranger@example.com', 'stranger-password');

        $this->client->request('GET', "/api/lists/{$this->list->getId()}");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', "/api/lists/{$this->list->getId()}/entries");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/api/lists');
        self::assertSame([], $this->json());
    }

    public function testASharedListShowsItsOwnersProgressNotTheVisitors(): void
    {
        // The visitor has seen the third film, which the owner has not. A shared profile reads
        // as its owner's everywhere — the stars on every card are theirs — so the list's
        // tally has to be theirs too, or one page would mix two people's libraries.
        $visitor = $this->user('lists-visitor@example.com', 'visitor-password');
        $this->entityManager->persist(new ProfileAccess($this->owner, $visitor));
        $third = $this->entityManager->getRepository(Movie::class)->findOneBy(['letterboxdSlug' => 'zz-list-three']);
        self::assertNotNull($third);
        $this->watch($visitor, $third, 4.0);
        $this->entityManager->flush();

        $this->login('lists-visitor@example.com', 'visitor-password');
        $this->client->request('GET', '/api/lists', ['profileId' => (string) $this->owner->getId()]);
        self::assertResponseIsSuccessful();

        self::assertSame(2, $this->json()[0]['watchedCount']);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function titles(array $query = []): array
    {
        $this->client->request('GET', "/api/lists/{$this->list->getId()}/entries", $query);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $item) => $item['movie']['title'], $this->json()['items']);
    }

    private function seedList(): void
    {
        $genre = (new Genre())->setName(self::GENRE);
        $this->entityManager->persist($genre);

        $one = $this->movie('zz-list-one', 'Un', 90, 'zz-one.jpg');
        $two = $this->movie('zz-list-two', 'Deux', 120, 'zz-two.jpg')->addGenre($genre);
        $three = $this->movie('zz-list-three', 'Trois', 100, null);
        $four = $this->movie('zz-list-four', 'Quatre', 1800, 'zz-four.jpg')->setMediaType(MediaType::SERIES);

        $this->watch($this->owner, $one, 4.0);
        $this->watch($this->owner, $one, 3.0);
        $this->watch($this->owner, $two, 2.0);

        $this->list = (new MovieList($this->owner, 'https://boxd.it/zzTop', 'Mon top'))->setTags(['classique']);
        $this->entityManager->persist($this->list);
        foreach ([$one, $two, $three, $four] as $index => $movie) {
            $entry = new MovieListEntry($this->list, $movie, $index + 1);
            $this->list->addEntry($entry);
            $this->entityManager->persist($entry);
        }

        $this->entityManager->flush();
    }

    private function movie(string $slug, string $title, int $runtime, ?string $poster): Movie
    {
        $movie = (new Movie($slug, $title))
            ->setReleaseYear(2000)
            ->setRuntimeMinutes($runtime)
            ->setPosterPath(null !== $poster ? '/'.$poster : null);
        $this->entityManager->persist($movie);

        return $movie;
    }

    private function watch(User $user, Movie $movie, float $rating): void
    {
        $watch = (new Watch($user, $movie, WatchSource::CSV_IMPORT))
            ->setWatchedDate(new \DateTimeImmutable('2026-01-01'))
            ->setRating($rating);
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);
    }

    private function user(string $email, string $password): User
    {
        $user = new User($email, 'Listes');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password])
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
