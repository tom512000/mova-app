<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
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
 * The shelf, over real HTTP against a real database.
 *
 * Badges are stored nowhere, so every one of them is the output of a window function over
 * the watch rows — there is no fixture that could stand in for it, and nothing here could be
 * exercised against a mock. What is pinned below is mostly the arithmetic: which rung a
 * tally lands on, and which evening the badge says it was earned on. Both are invisible when
 * wrong, which is the whole reason they are asserted rather than looked at.
 */
final class BadgeControllerTest extends WebTestCase
{
    private const EMAIL = 'badges@example.com';
    private const PASSWORD = 'badges-password';
    private const STRANGER_EMAIL = 'badges-stranger@example.com';

    /** Prefixed so they cannot collide with the globally unique genre names already stored. */
    private const WIDE = 'ZZ-Badge-Partout';
    private const HALF = 'ZZ-Badge-Moitie';
    private const THIN = 'ZZ-Badge-Trop-Peu';

    private const REGULAR = 'ZZ Acteur Regulier';
    private const DOUBLED = 'ZZ Acteur Credite Deux Fois';
    private const ELSEWHERE = 'ZZ Acteur D Un Autre Compte';

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

    public function testASecondRungCostsTwiceTheFirst(): void
    {
        // Ten works of one genre: five bought the first level, the tenth the second. The
        // next one is twenty, not fifteen — the ladder doubles, which is what keeps four
        // hundred comedies from reading as level eighty-three.
        $badge = $this->badgeFor('genre', self::WIDE);

        self::assertSame(2, $badge['level']);
        self::assertSame(10, $badge['workCount']);
        self::assertSame(10, $badge['worksToNextLevel']);
    }

    public function testTheBadgeIsDatedToTheEveningItWasEarned(): void
    {
        // Not the day the shelf was looked at, and not the latest film of the genre: the
        // watch date of the tenth one, which is the one that bought the level it stands at.
        self::assertSame('2024-01-10', $this->badgeFor('genre', self::WIDE)['earnedOn']);

        // And the fifth, for the genre that only ever reached the first rung.
        self::assertSame('2024-01-05', $this->badgeFor('genre', self::HALF)['earnedOn']);
    }

    public function testARevisedRatingIsNotAnEveningAndCannotReorderTheLadder(): void
    {
        // The tenth film carries a note deduced from a re-rating and dated years earlier.
        // Counted as a viewing it would sort first, some other film would become the tenth,
        // and the badge above would be dated to that film's evening instead.
        self::assertSame('2024-01-10', $this->badgeFor('genre', self::WIDE)['earnedOn']);
    }

    public function testAGenreShortOfTheFirstRungEarnsNothing(): void
    {
        self::assertNull($this->findBadge('genre', self::THIN));
    }

    public function testAWorkCreditedTwiceCountsOnce(): void
    {
        // Both actors are in four films. One of them carries two credits on one of those
        // films, which TMDB does on an ensemble cast — counted at credit level that is five
        // rows, and a badge nobody earned.
        self::assertNull($this->findBadge('actor', self::DOUBLED));

        // The control: five films, five credits, a badge.
        $regular = $this->findBadge('actor', self::REGULAR);
        self::assertNotNull($regular);
        self::assertSame(1, $regular['level']);
        self::assertSame(5, $regular['workCount']);
    }

    public function testAnotherAccountsLibraryEarnsNothingHere(): void
    {
        // The movie table is a shared catalogue, so this is what stands between a shelf and
        // a list of everybody's achievements.
        self::assertNull($this->findBadge('actor', self::ELSEWHERE));
    }

    public function testABadgeWearsAStillFromAWorkThatEarnedIt(): void
    {
        $badge = $this->badgeFor('genre', self::WIDE);

        self::assertNotNull($badge['imageUrl']);
        self::assertStringContainsString('/zz-badge-', $badge['imageUrl']);

        // And it keeps it: the draw is a hash of the badge's own subject, not a shuffle, so
        // a badge does not change face between two visits.
        self::assertSame($badge['imageUrl'], $this->badgeFor('genre', self::WIDE)['imageUrl']);
    }

    public function testTheDecadeIsCountedFromTheReleaseYear(): void
    {
        $badge = $this->badgeFor('decade', '2010');

        self::assertSame(2, $badge['level']);
        self::assertSame(10, $badge['workCount']);
    }

    public function testTheShelfIsOrderedByLevelAndCountsItsCategories(): void
    {
        $payload = $this->shelf('');

        // The strip on the profile takes the first few of exactly this order, so what it
        // shows has to be the best of the shelf rather than whatever came back first.
        $levels = array_column($payload['items'], 'level');
        $descending = $levels;
        rsort($descending);
        self::assertSame($descending, $levels, 'highest level first');

        $counts = $payload['counts'];
        ksort($counts);
        self::assertSame(['actor' => 1, 'decade' => 1, 'genre' => 2], $counts);
    }

    public function testNarrowingToOneCategoryDropsTheCounts(): void
    {
        $payload = $this->shelf('category=actor');

        self::assertNull($payload['counts'], 'a narrowed shelf has nothing to say about the categories it excluded');
        self::assertSame([self::REGULAR], array_column($payload['items'], 'label'));
    }

    public function testAnUnknownCategoryFallsBackToTheWholeShelfRatherThanFailing(): void
    {
        // These arrive from the address bar, and a stale bookmark should still show badges.
        self::assertSame($this->shelf('')['total'], $this->shelf('category=quelque-chose')['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function badgeFor(string $category, string $label): array
    {
        $badge = $this->findBadge($category, $label);
        self::assertNotNull($badge, sprintf('no %s badge for "%s"', $category, $label));

        return $badge;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBadge(string $category, string $label): ?array
    {
        foreach ($this->shelf('perPage=200')['items'] as $badge) {
            if ($category === $badge['category'] && $label === $badge['label']) {
                return $badge;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function shelf(string $queryString): array
    {
        $this->client->request('GET', '/api/badges?perPage=200&'.$queryString);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Ten works watched one a day, so every rung below falls on a date that can be named.
     */
    private function seedLibrary(): void
    {
        $user = $this->account(self::EMAIL, 'Badges');
        $stranger = $this->account(self::STRANGER_EMAIL, 'Stranger');

        $wide = $this->genre(self::WIDE);
        $half = $this->genre(self::HALF);
        $thin = $this->genre(self::THIN);

        $regular = $this->person(self::REGULAR);
        $doubled = $this->person(self::DOUBLED);

        /** @var list<Movie> $films */
        $films = [];
        for ($index = 1; $index <= 10; ++$index) {
            $film = $this->movie(sprintf('ZZ Badge %02d', $index));
            $film->addGenre($wide);

            if ($index <= 5) {
                $film->addGenre($half);
                $this->credit($film, $regular, CreditRole::ACTOR, 'Le role');
            }

            if ($index <= 4) {
                $film->addGenre($thin);
                $this->credit($film, $doubled, CreditRole::ACTOR, 'Le juge');
            }

            $this->watch($user, $film, sprintf('2024-01-%02d', $index));
            $films[] = $film;
        }

        // The same person, credited a second time on one film under another character name.
        // Four films, five credit rows — and no badge, which is the point.
        $this->credit($films[0], $doubled, CreditRole::ACTOR, 'Le frere du juge');

        // A note moved long before any of these evenings. It must not be read as a viewing:
        // sorted in, it would take the tenth place and misdate the badge above.
        $revision = new Watch($user, $films[9], WatchSource::CSV_RERATING);
        $revision->setWatchedDate(new \DateTimeImmutable('2019-05-05'));
        $revision->setRating(4.0);
        $this->entityManager->persist($revision);

        // Another account's five films, with an actor of their own.
        $elsewhere = $this->person(self::ELSEWHERE);
        for ($index = 1; $index <= 5; ++$index) {
            $film = $this->movie(sprintf('ZZ Badge Ailleurs %02d', $index));
            $this->credit($film, $elsewhere, CreditRole::ACTOR, null);
            $this->watch($stranger, $film, sprintf('2024-02-%02d', $index));
        }

        $this->entityManager->flush();
    }

    private function account(string $email, string $displayName): User
    {
        $user = new User($email, $displayName);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD)
        );
        $this->entityManager->persist($user);

        return $user;
    }

    private function genre(string $name): Genre
    {
        $genre = (new Genre())->setName($name);
        $this->entityManager->persist($genre);

        return $genre;
    }

    private function person(string $name): Person
    {
        $person = (new Person())->setName($name);
        $this->entityManager->persist($person);

        return $person;
    }

    private function movie(string $title): Movie
    {
        $movie = new Movie('test-'.md5($title), $title);
        // Every one of them in the same decade, so the decade badge has ten works too.
        $movie->setReleaseYear(2011);
        $movie->setBackdropPath('/zz-badge-'.md5($title).'.jpg');
        $this->entityManager->persist($movie);

        return $movie;
    }

    private function credit(Movie $movie, Person $person, CreditRole $role, ?string $characterName): void
    {
        $credit = new Credit($movie, $person, $role);
        $credit->setCharacterName($characterName);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
    }

    private function watch(User $user, Movie $movie, string $date): void
    {
        $watch = new Watch($user, $movie, WatchSource::MANUAL);
        $watch->setWatchedDate(new \DateTimeImmutable($date));
        $watch->setRating(3.5);
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
}
