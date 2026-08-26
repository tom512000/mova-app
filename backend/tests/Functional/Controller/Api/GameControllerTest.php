<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\GameSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The "guess the film" game end to end. The rule worth defending here is that the answer
 * never appears in a response while the run is open — everything else is bookkeeping.
 */
final class GameControllerTest extends WebTestCase
{
    private const EMAIL = 'player@example.com';
    private const PASSWORD = 'player-password';

    /** Enough films to lose a full run and still have one left to win with. */
    private const LIBRARY_SIZE = 10;

    /**
     * The reveal order, from the fact that fits hundreds of films to the one that fits two.
     * Modelled on spotle.movie.
     */
    private const CLUE_ORDER = [
        'Genres',
        'Année de sortie',
        'Pays de production',
        'Studios',
        'Réalisateur·rice',
        'Acteur·rice·s secondaires',
        'Acteur·rice principal·e',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private GameSessionRepository $sessions;
    private User $player;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->sessions = self::getContainer()->get(GameSessionRepository::class);
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

    public function testNoRunUntilOneIsStarted(): void
    {
        $this->client->request('GET', '/api/games/film/daily');

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['session']);
    }

    public function testStartingDealsOneClueAndHidesTheAnswer(): void
    {
        $state = $this->start('daily');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('in_progress', $state['status']);
        self::assertSame(0, $state['attemptsUsed']);
        self::assertCount(1, $state['clues'], 'the opening move must not be blind, but only just');
        self::assertSame('Genres', $state['clues'][0]['label']);
        self::assertSame(\count(self::CLUE_ORDER), $state['maxAttempts']);
        self::assertNull($state['answer']);
        self::assertSame([], $state['guesses']);
    }

    public function testTheDailyRunIsTheSameOneAllDay(): void
    {
        $first = $this->start('daily');
        $this->guess('daily', $this->aWrongMovieId());

        // Asking again mid-run must resume, not deal a fresh board.
        $second = $this->start('daily');

        self::assertSame($first['puzzleDate'], $second['puzzleDate']);
        self::assertSame(1, $second['attemptsUsed']);
        self::assertCount(2, $second['clues']);
    }

    public function testEachWrongGuessTurnsOverOneMoreClue(): void
    {
        $state = $this->start('daily');
        $wrong = $this->wrongMovieIds();

        foreach ([1, 2, 3] as $attempt) {
            $state = $this->guess('daily', $wrong[$attempt - 1]);

            self::assertSame($attempt, $state['attemptsUsed']);
            self::assertCount($attempt + 1, $state['clues']);
            self::assertNull($state['answer'], 'the answer must stay hidden while the run is open');
        }

        self::assertFalse($state['guesses'][0]['correct']);
    }

    public function testTheSameFilmCannotBePlayedTwice(): void
    {
        $this->start('daily');
        $movieId = $this->aWrongMovieId();
        $this->guess('daily', $movieId);

        $this->guess('daily', $movieId);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('deja propose', $this->deaccent($this->json()['error']));
    }

    public function testAFilmOutsideTheLibraryIsRefused(): void
    {
        $this->start('daily');

        $this->guess('daily', 99999999);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testFindingItEndsTheRunAndRevealsTheAnswer(): void
    {
        $this->start('daily');
        $this->guess('daily', $this->aWrongMovieId());

        $answerId = $this->answerId(GameMode::DAILY);
        $state = $this->guess('daily', $answerId);

        self::assertSame('won', $state['status']);
        self::assertSame(2, $state['attemptsUsed']);
        self::assertSame($answerId, $state['answer']['id']);
        self::assertTrue($state['guesses'][1]['correct']);
        // Winning early still opens the whole ladder, so the film can be read in full.
        self::assertSame(
            self::CLUE_ORDER,
            array_map(static fn (array $clue) => $clue['label'], $state['clues']),
            'the reveal order is the difficulty setting; changing it is never accidental'
        );

        // And the run is closed for good.
        $this->guess('daily', $this->wrongMovieIds()[1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRunningOutOfGuessesLosesAndRevealsTheAnswer(): void
    {
        $state = $this->start('daily');
        $maxAttempts = $state['maxAttempts'];
        $wrong = $this->wrongMovieIds();
        self::assertGreaterThanOrEqual($maxAttempts, \count($wrong), 'the fixture must allow a full run of misses');

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $state = $this->guess('daily', $wrong[$attempt]);
        }

        self::assertSame('lost', $state['status']);
        self::assertSame($this->answerId(GameMode::DAILY), $state['answer']['id']);
        self::assertCount($maxAttempts, $state['clues']);
    }

    public function testTheInfiniteModeDealsANewBoardOnDemand(): void
    {
        $first = $this->start('infinite');
        $this->guess('infinite', $this->aWrongMovieId(GameMode::INFINITE));

        $second = $this->start('infinite');

        self::assertSame(0, $second['attemptsUsed'], 'starting again must abandon the open run');
        self::assertNull($second['puzzleDate'], 'only the daily mode is pinned to a date');
        self::assertSame([], $second['guesses']);
        self::assertNotNull($first['maxAttempts']);
    }

    public function testTheTwoModesRunSideBySide(): void
    {
        $this->start('daily');
        $this->guess('daily', $this->aWrongMovieId());
        $this->start('infinite');

        $this->client->request('GET', '/api/games/film/daily');
        self::assertSame(1, $this->json()['session']['attemptsUsed'], 'the infinite run must not touch the daily one');
    }

    /**
     * @return array<string, mixed>
     */
    private function start(string $mode): array
    {
        $this->client->request('POST', "/api/games/film/{$mode}/start");
        self::assertResponseIsSuccessful();

        return $this->json()['session'];
    }

    /**
     * @return array<string, mixed>
     */
    private function guess(string $mode, int $movieId): array
    {
        $this->client->request(
            'POST',
            "/api/games/film/{$mode}/guess",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['movieId' => $movieId])
        );

        return $this->json()['session'] ?? [];
    }

    private function answerId(GameMode $mode): int
    {
        $session = GameMode::DAILY === $mode
            ? $this->sessions->findDaily($this->player, new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            : $this->sessions->findLatestInfinite($this->player);

        self::assertNotNull($session);

        return (int) $session->getMovie()->getId();
    }

    /**
     * @return list<int>
     */
    private function wrongMovieIds(GameMode $mode = GameMode::DAILY): array
    {
        $answerId = $this->answerId($mode);

        return array_values(array_filter(
            array_map(static fn (Movie $movie) => (int) $movie->getId(), $this->library),
            static fn (int $id) => $id !== $answerId
        ));
    }

    private function aWrongMovieId(GameMode $mode = GameMode::DAILY): int
    {
        return $this->wrongMovieIds($mode)[0];
    }

    /** @var list<Movie> */
    private array $library = [];

    private function seedLibrary(): void
    {
        $this->player = new User(self::EMAIL, 'Player');
        $this->player->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->player, self::PASSWORD)
        );
        $this->entityManager->persist($this->player);

        $genre = (new Genre())->setName('ZZ-Jeu-Genre');
        $this->entityManager->persist($genre);

        $country = (new Country())->setIsoCode('ZZ')->setName('ZZ-Jeu-Pays');
        $this->entityManager->persist($country);

        $studio = (new Studio())->setTmdbId(999_000_001)->setName('ZZ-Jeu-Studio');
        $this->entityManager->persist($studio);

        for ($index = 1; $index <= self::LIBRARY_SIZE; ++$index) {
            $movie = new Movie(sprintf('zz-jeu-%02d', $index), sprintf('ZZ Film %02d', $index));
            $movie->setReleaseYear(2000 + $index);
            $movie->setRuntimeMinutes(90 + $index);
            $movie->addGenre($genre);
            $movie->addCountry($country);
            $movie->addStudio($studio);
            $this->entityManager->persist($movie);

            $this->credit($movie, sprintf('ZZ Real %02d', $index), CreditRole::DIRECTOR, null);
            // Three billed actors is the floor the picker requires for a playable film.
            foreach ([0, 1, 2] as $order) {
                $this->credit($movie, sprintf('ZZ Acteur %02d-%d', $index, $order), CreditRole::ACTOR, $order);
            }

            $watch = new Watch($this->player, $movie, WatchSource::MANUAL);
            $watch->setWatchedDate(new \DateTimeImmutable('2024-01-01'));
            $watch->setRating(3.5);
            $this->entityManager->persist($watch);

            $this->library[] = $movie;
        }

        $this->entityManager->flush();
    }

    private function credit(Movie $movie, string $name, CreditRole $role, ?int $castOrder): void
    {
        $person = (new Person())->setName($name);
        $this->entityManager->persist($person);

        $credit = new Credit($movie, $person, $role);
        $credit->setCastOrder($castOrder);
        $movie->addCredit($credit);
        $this->entityManager->persist($credit);
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

    /** Keeps the assertions readable without pasting accented literals into them. */
    private function deaccent(string $value): string
    {
        return strtr($value, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
