<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\WatchlistEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every read endpoint, called once, against a profile that actually has something in it.
 *
 * Four of the eight API controllers had no functional test at all, which is how a mapper
 * handing a Uuid object to a DTO expecting a string reached the browser: nothing between
 * the entity and the JSON was ever executed. This does not check what any endpoint says —
 * that is each controller's own test's job where one exists. It checks that they all still
 * answer, which is the assertion that was missing when the primary keys changed type.
 *
 * The route list is written out by hand rather than read from the router on purpose: a new
 * endpoint should have to be added here deliberately, and a route quietly disappearing
 * should fail this file rather than shrink it.
 */
final class ReadEndpointsSmokeTest extends WebTestCase
{
    private const EMAIL = 'smoke@example.com';
    private const PASSWORD = 'smoke-password';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Movie $movie;
    private Person $director;

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function readRoutes(): iterable
    {
        yield 'me' => ['/api/auth/me'];
        yield 'movies' => ['/api/movies'];
        yield 'movie posters' => ['/api/movies/posters'];
        yield 'movie facets' => ['/api/movies/facets'];
        yield 'profiles' => ['/api/profiles'];
        yield 'share link' => ['/api/profiles/share-link'];
        yield 'letterboxd profile' => ['/api/profiles/letterboxd'];
        yield 'watchlist' => ['/api/watchlist'];
        yield 'sync status' => ['/api/sync/letterboxd'];
        yield 'imports' => ['/api/import'];
        yield 'stats overview' => ['/api/stats/overview'];
        yield 'stats timeline' => ['/api/stats/timeline'];
        yield 'stats ratings' => ['/api/stats/ratings'];
        yield 'stats genres' => ['/api/stats/genres'];
        yield 'stats directors' => ['/api/stats/directors'];
        yield 'stats creators' => ['/api/stats/creators'];
        yield 'stats actors' => ['/api/stats/actors'];
        yield 'stats writers' => ['/api/stats/writers'];
        yield 'stats producers' => ['/api/stats/producers'];
        yield 'stats decades' => ['/api/stats/decades'];
        yield 'stats countries' => ['/api/stats/countries'];
        yield 'stats activity' => ['/api/stats/activity'];
        yield 'stats at release' => ['/api/stats/at-release'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readRoutes')]
    public function testTheEndpointAnswers(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful($path);
    }

    public function testTheRoutesThatTakeAnIdAnswerForARealOne(): void
    {
        // Separate from the list above because these need an id minted by the fixture, and
        // an id is exactly the thing that broke.
        foreach ([
            "/api/movies/{$this->movie->getId()}",
            '/api/movies?personId='.$this->director->getId(),
        ] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testEveryApiResponseRefusesToBeIndexed(): void
    {
        // Symfony sets this header itself, but only while kernel.debug is on — so it is
        // present through every local run and absent in the one environment where a search
        // engine could reach the endpoint. Asserting it here is what makes the production
        // behaviour observable, since the test suite runs with debug on and would pass
        // either way if it only looked at whether the header existed at all.
        $this->client->request('GET', '/api/stats/overview');

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function testAFailedResponseCarriesItToo(): void
    {
        // The responses a crawler is most likely to collect are the ones no controller
        // returned — a 401 from the firewall, a 404 from the router. Listening on
        // kernel.response rather than sitting in a base controller is what covers them, and
        // this is the assertion that says so.
        $this->client->request('GET', '/api/movies/01920000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    private function seed(): void
    {
        $this->user = new User(self::EMAIL, 'Smoke');
        $this->user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::PASSWORD)
        );
        $this->entityManager->persist($this->user);

        $genre = (new Genre())->setName('ZZ-Smoke-Genre');
        $country = (new Country())->setIsoCode('ZS')->setName('ZZ-Smoke-Pays');
        $studio = (new Studio())->setTmdbId(999_000_777)->setName('ZZ-Smoke-Studio');
        foreach ([$genre, $country, $studio] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->movie = new Movie('zz-smoke-film', 'ZZ Film Smoke');
        $this->movie->setReleaseYear(2015)
            ->setRuntimeMinutes(120)
            ->setReleaseDate(new \DateTimeImmutable('2015-04-02'))
            ->setPosterPath('/zz-smoke.jpg');
        $this->movie->addGenre($genre)->addCountry($country)->addStudio($studio);
        $this->entityManager->persist($this->movie);

        // One of each credit role: four separate stats endpoints read one role each, and a
        // fixture with only actors would let three of them pass on an empty result.
        foreach ([CreditRole::DIRECTOR, CreditRole::WRITER, CreditRole::ACTOR] as $index => $role) {
            $person = (new Person())->setName('ZZ Smoke '.$role->value);
            $this->entityManager->persist($person);

            $credit = new Credit($this->movie, $person, $role);
            $credit->setCastOrder(CreditRole::ACTOR === $role ? $index : null);
            $this->movie->addCredit($credit);
            $this->entityManager->persist($credit);

            if (CreditRole::DIRECTOR === $role) {
                $this->director = $person;
            }
        }

        $watch = new Watch($this->user, $this->movie, WatchSource::MANUAL);
        $watch->setWatchedDate(new \DateTimeImmutable('2026-02-14'))->setRating(4.0);
        $this->entityManager->persist($watch);

        // A series and whoever created it: /stats/creators reads that role and nothing else
        // in this fixture carries it.
        $series = new Movie('zz-smoke-serie', 'ZZ Serie Smoke');
        $series->setMediaType(MediaType::SERIES)->setReleaseYear(2021)->setRuntimeMinutes(600);
        $this->entityManager->persist($series);

        $creator = (new Person())->setName('ZZ Smoke creator');
        $this->entityManager->persist($creator);
        $credit = new Credit($series, $creator, CreditRole::CREATOR);
        $series->addCredit($credit);
        $this->entityManager->persist($credit);

        $seriesWatch = new Watch($this->user, $series, WatchSource::MANUAL);
        $seriesWatch->setWatchedDate(new \DateTimeImmutable('2026-03-01'))->setRating(5.0);
        $this->entityManager->persist($seriesWatch);

        $wanted = new Movie('zz-smoke-envie', 'ZZ Film À Voir');
        $wanted->setReleaseYear(2024)->setPosterPath('/zz-smoke-envie.jpg');
        $this->entityManager->persist($wanted);
        $this->entityManager->persist(new WatchlistEntry($this->user, $wanted));

        $this->entityManager->flush();
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
