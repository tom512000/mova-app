<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\WatchlistEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The directory of people, over real HTTP against a real database.
 *
 * Everything asserted here lives in one SQL statement — the scoping to a profile, the
 * collapsing of several credits on one work, the per-job tallies — so none of it can be
 * exercised against a mock. The two that matter most are the two that would be invisible in
 * the browser: a page that quietly counts an ensemble actor's film twice looks exactly like
 * one that does not, and so does a directory showing somebody another account watched.
 */
final class PersonControllerTest extends WebTestCase
{
    private const EMAIL = 'people@example.com';
    private const PASSWORD = 'people-password';
    private const STRANGER_EMAIL = 'people-stranger@example.com';

    /** Prefixed so they cannot collide with whatever else the shared catalogue holds. */
    private const ALICE = 'ZZ Alice Auteur';
    private const BRUNO = 'ZZ Bruno Bis';
    private const CLARA = 'ZZ Clara Casquette';
    private const DORA = 'ZZ Dora Distante';
    private const EVA = 'ZZ Eva Envie';
    private const FELIX = 'ZZ Felix Feuille';

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

    public function testDefaultsToTheMostWatchedFirst(): void
    {
        // Eva is last and not absent: somebody met only in the watchlist is still somebody
        // the directory shows, which is the whole difference from the dashboard rankings.
        self::assertSame(
            [self::ALICE, self::BRUNO, self::CLARA, self::FELIX, self::EVA],
            $this->namesFor('')
        );
    }

    public function testAWorkCreditedTwiceIsCountedOnce(): void
    {
        // Bruno is credited twice on the same film, under two character names — TMDB does
        // that on an ensemble cast. At credit level he would come out with three works and
        // an average of 3, both of them wrong by exactly one film.
        $bruno = $this->cardFor(self::BRUNO, '');

        self::assertSame(2, $bruno['watchedCount']);
        self::assertSame(2, $bruno['workCount']);
        self::assertSame(2.5, $bruno['averageRating']);
    }

    public function testTheSeveralJobsHeldOnOneWorkAreCountedOnce(): void
    {
        // The other shape of the same problem: Clara writes and directs the same film, so
        // it carries two of her credits. Counted at credit level it would weigh double in
        // her average — 1.67 rather than 1.5.
        $clara = $this->cardFor(self::CLARA, '');

        self::assertSame(2, $clara['watchedCount']);
        self::assertSame(1.5, $clara['averageRating']);
        // Credit-block order, not the alphabetical one the SQL aggregate hands back.
        self::assertSame(['director', 'writer', 'actor'], $clara['roles']);
    }

    public function testAJobFilterCountsAndRatesThatJobAlone(): void
    {
        self::assertSame([self::ALICE, self::CLARA], $this->namesFor('role=director'));

        // Alice acts in a film she rated 1, and it must not reach her directing average.
        $alice = $this->cardFor(self::ALICE, 'role=director');
        self::assertSame(2, $alice['watchedCount']);
        // Cast: a whole average comes back over JSON as 3 rather than 3.0.
        self::assertSame(3.0, (float) $alice['averageRating']);
        self::assertSame(['director'], $alice['roles']);
    }

    public function testAnotherAccountsLibraryDoesNotLeak(): void
    {
        // The movie table is a shared catalogue, so this is the one assertion standing
        // between a directory and a dump of everybody's cast lists.
        self::assertNotContains(self::DORA, $this->namesFor('perPage=100'));
    }

    public function testWhatIsWaitingIsCountedApartFromWhatWasSeen(): void
    {
        $eva = $this->cardFor(self::EVA, '');

        self::assertSame(0, $eva['watchedCount']);
        self::assertSame(1, $eva['watchlistCount']);
        self::assertSame(1, $eva['workCount']);
        self::assertNull($eva['averageRating']);
        self::assertNull($eva['lastWatchedDate']);
    }

    public function testTheSearchMatchesOnTheNameWhateverTheCase(): void
    {
        self::assertSame([self::BRUNO], $this->namesFor('q=bRuNo'));
        self::assertSame([], $this->namesFor('q=ZZ%20Personne%20Inexistante'));
    }

    public function testSeriesNarrowTheDirectoryToWhoeverMadeThem(): void
    {
        self::assertSame([self::FELIX], $this->namesFor('mediaType=series'));
        self::assertNotContains(self::FELIX, $this->namesFor('mediaType=movie'));
    }

    public function testTheAlphabeticalAndRatingSorts(): void
    {
        self::assertSame(
            [self::ALICE, self::BRUNO, self::CLARA, self::EVA, self::FELIX],
            $this->namesFor('sort=name')
        );

        // Eva has nothing to rank on, so she stays at the bottom whichever way the sort
        // points — she is never the lowest note.
        self::assertSame(
            [self::FELIX, self::BRUNO, self::ALICE, self::CLARA, self::EVA],
            $this->namesFor('sort=rating')
        );
        self::assertSame(
            [self::CLARA, self::ALICE, self::BRUNO, self::FELIX, self::EVA],
            $this->namesFor('sort=rating&direction=asc')
        );
    }

    public function testARevisedRatingDoesNotMakeSomebodyLookFreshlyWatched(): void
    {
        // Alice's oldest film carries a note moved in 2026, long after every real viewing
        // in this library. Counted as a viewing it would put her first here; it is a real
        // rating but not an evening spent with her work, so it does not.
        self::assertSame(
            [self::FELIX, self::CLARA, self::ALICE, self::BRUNO, self::EVA],
            $this->namesFor('sort=recent')
        );

        self::assertSame('2024-03-05', $this->cardFor(self::ALICE, '')['lastWatchedDate']);
    }

    public function testThePageIsBoundedAndTheTotalIsNot(): void
    {
        $this->client->request('GET', '/api/people?perPage=2&page=2');
        self::assertResponseIsSuccessful();

        $payload = $this->json();
        self::assertSame(5, $payload['total']);
        self::assertSame(2, $payload['page']);
        self::assertSame(2, $payload['perPage']);
        self::assertSame([self::CLARA, self::FELIX], array_column($payload['items'], 'name'));
    }

    public function testAJobNobodyHoldsAnswersWithAnEmptyDirectory(): void
    {
        // The job dropdown offers all five whatever the library holds, so this is reachable
        // by design rather than by a mistyped URL. Nobody here produces anything, and the
        // page has to say so plainly instead of erroring on an aggregate over no rows.
        $this->client->request('GET', '/api/people?role=producer');
        self::assertResponseIsSuccessful();

        $payload = $this->json();
        self::assertSame(0, $payload['total']);
        self::assertSame([], $payload['items']);
    }

    public function testAnUnknownSortFallsBackRatherThanFailing(): void
    {
        // These arrive from the address bar, so a stale bookmark still shows the directory.
        self::assertSame($this->namesFor(''), $this->namesFor('sort=runtime&direction=sideways'));
    }

    /**
     * @return list<string>
     */
    private function namesFor(string $queryString): array
    {
        $this->client->request('GET', '/api/people?perPage=50&'.$queryString);
        self::assertResponseIsSuccessful();

        return array_column($this->json()['items'], 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function cardFor(string $name, string $queryString): array
    {
        $this->namesFor($queryString);

        foreach ($this->json()['items'] as $item) {
            if ($name === $item['name']) {
                return $item;
            }
        }

        self::fail(sprintf('"%s" is not in the directory for "%s".', $name, $queryString));
    }

    /**
     * A library small enough to reason about and wide enough to break the query: somebody
     * credited twice on one film, somebody holding two jobs on one film, a series, a name
     * reachable only through the watchlist, and a name belonging to another account.
     */
    private function seedLibrary(): void
    {
        $user = $this->account(self::EMAIL, 'People');
        $stranger = $this->account(self::STRANGER_EMAIL, 'Stranger');

        $agora = $this->movie('ZZ Agora', 2001);
        $brasier = $this->movie('ZZ Brasier', 1985);
        $cendres = $this->movie('ZZ Cendres', 1999);
        $dune = $this->movie('ZZ Dune', 2021);
        $etoile = $this->movie('ZZ Etoile', 2022, MediaType::SERIES);
        $fuite = $this->movie('ZZ Fuite', 2010);
        $gouffre = $this->movie('ZZ Gouffre', 2015);

        $this->watch($user, $agora, '2024-01-10', 4.0);
        $this->watch($user, $brasier, '2024-02-20', 2.0);
        $this->watch($user, $cendres, '2024-03-05', 1.0);
        $this->watch($user, $dune, '2024-04-15', 2.0);
        $this->watch($user, $etoile, '2024-05-25', 3.0);

        // A note moved two years later, without the film being watched again.
        $revision = new Watch($user, $brasier, WatchSource::CSV_RERATING);
        $revision->setWatchedDate(new \DateTimeImmutable('2026-09-01'));
        $revision->setRating(2.0);
        $this->entityManager->persist($revision);

        // Never watched, only waiting.
        $entry = new WatchlistEntry($user, $gouffre);
        $entry->setAddedDate(new \DateTimeImmutable('2024-06-01'));
        $this->entityManager->persist($entry);

        // Another account's evening, on a film this profile has never touched.
        $this->watch($stranger, $fuite, '2024-07-01', 5.0);

        $alice = $this->person(self::ALICE);
        $this->credit($agora, $alice, CreditRole::DIRECTOR);
        $this->credit($brasier, $alice, CreditRole::DIRECTOR);
        $this->credit($brasier, $alice, CreditRole::WRITER);
        $this->credit($cendres, $alice, CreditRole::ACTOR);

        $bruno = $this->person(self::BRUNO);
        $this->credit($agora, $bruno, CreditRole::ACTOR, 'Le juge');
        $this->credit($agora, $bruno, CreditRole::ACTOR, 'Le frère du juge');
        $this->credit($cendres, $bruno, CreditRole::ACTOR, 'Un passant');

        $clara = $this->person(self::CLARA);
        $this->credit($dune, $clara, CreditRole::DIRECTOR);
        $this->credit($dune, $clara, CreditRole::WRITER);
        $this->credit($cendres, $clara, CreditRole::ACTOR, 'La libraire');

        $this->credit($fuite, $this->person(self::DORA), CreditRole::ACTOR);
        $this->credit($gouffre, $this->person(self::EVA), CreditRole::ACTOR);
        // Created, not directed: the series is why the facets offer a fifth job at all.
        $this->credit($etoile, $this->person(self::FELIX), CreditRole::CREATOR);

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

    private function movie(string $title, int $year, MediaType $mediaType = MediaType::MOVIE): Movie
    {
        $movie = new Movie('test-'.md5($title), $title);
        $movie->setReleaseYear($year);
        $movie->setMediaType($mediaType);
        $this->entityManager->persist($movie);

        return $movie;
    }

    private function person(string $name): Person
    {
        $person = (new Person())->setName($name);
        $this->entityManager->persist($person);

        return $person;
    }

    private function credit(Movie $movie, Person $person, CreditRole $role, ?string $characterName = null): void
    {
        $credit = new Credit($movie, $person, $role);
        $credit->setCharacterName($characterName);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
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
