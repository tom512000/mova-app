<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\GameSessionRepository;
use App\Service\Game\PosterPixelator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
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
     * How many of them TMDB has artwork for. Deliberately a minority: the poster game may
     * only ever draw from these, and a fixture where every film qualifies could not tell
     * the difference.
     */
    private const WITH_POSTER = 4;

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
        $this->seedPosterPixels();
        $this->login();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        foreach ($this->posterCacheKeys() as $key) {
            $this->posterCache()->deleteItem($key);
        }

        parent::tearDown();
    }

    public function testNoRunUntilOneIsStarted(): void
    {
        $this->client->request('GET', '/api/games/clue/daily');

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['session']);
    }

    public function testStartingDealsOneClueAndHidesTheAnswer(): void
    {
        $state = $this->start('daily');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('clue', $state['game']);
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

        // Well-formed but held by nobody, so it gets past the UUID check in the controller
        // and is refused for the reason this test is about: the film is not in the library.
        $this->guess('daily', '01920000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAMalformedFilmIdIsARequestErrorRatherThanAWastedGuess(): void
    {
        $this->start('daily');

        // Ids used to be integers, so a leftover client sending one — or anything else that
        // is not a UUID — must be turned away before it reaches the game and costs a life.
        $this->guess('daily', '99999999');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        // Starting again returns the same daily run, which must still be untouched.
        self::assertSame([], $this->start('daily')['guesses']);
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

        $this->client->request('GET', '/api/games/clue/daily');
        self::assertSame(1, $this->json()['session']['attemptsUsed'], 'the infinite run must not touch the daily one');
    }

    /**
     * @return array<string, mixed>
     */
    public function testTheComparisonGameAnswersWithVerdictsInsteadOfClues(): void
    {
        $state = $this->start('daily', 'compare');

        self::assertSame('compare', $state['game']);
        self::assertSame([], $state['clues'], 'the comparison game hands nothing out up front');
        self::assertSame(8, $state['maxAttempts']);

        $state = $this->guess('daily', $this->aWrongMovieId(GameMode::DAILY, GameKind::COMPARE), 'compare');

        self::assertCount(1, $state['guesses']);
        self::assertSame(
            ['Année', 'Durée', 'Genres', 'Pays', 'Studios', 'Réalisateur·rice', 'Casting'],
            array_map(static fn (array $facet) => $facet['label'], $state['guesses'][0]['facets'])
        );
        self::assertNull($state['answer'], 'a comparison must never carry the answer with it');
    }

    public function testEachGameHasItsOwnDailyPuzzle(): void
    {
        foreach (['clue', 'compare', 'poster', 'hangman'] as $game) {
            $this->start('daily', $game);
        }

        // Playing one must not spend another's single run for the day.
        $this->guess('daily', $this->aWrongMovieId(GameMode::DAILY, GameKind::CLUE), 'clue');

        foreach (['compare', 'poster', 'hangman'] as $game) {
            $this->client->request('GET', "/api/games/{$game}/daily");
            self::assertSame(0, $this->json()['session']['attemptsUsed'], $game);
        }
    }

    public function testTheHangmanDealsAMaskedTitleAndKeepsTheRealOne(): void
    {
        $state = $this->start('daily', 'hangman');

        self::assertSame('hangman', $state['game']);
        self::assertSame([], $state['clues']);
        self::assertSame(7, $state['maxAttempts'], 'seven lives, and the board must agree');

        $board = $state['hangman'];
        self::assertSame(7, $board['livesLeft']);
        self::assertSame([], $board['tried']);
        // Word shape and punctuation are the board; the letters are not.
        self::assertContains(null, $board['chars'], 'nothing was masked');
        self::assertContains(' ', $board['chars'], 'the spaces belong on the board');

        $answer = $this->entityManager->find(Movie::class, $this->answerId(GameMode::DAILY, GameKind::HANGMAN));
        self::assertNotNull($answer);
        self::assertStringNotContainsString(
            $answer->getTitle(),
            (string) $this->client->getResponse()->getContent(),
            'the payload spelled the title out'
        );
    }

    public function testALetterInTheTitleRevealsItselfAndCostsNothing(): void
    {
        $this->start('daily', 'hangman');

        // Every fixture title is "ZZ Film NN", so its letters are known without knowing
        // which film was drawn.
        $state = $this->letter('daily', 'F');

        self::assertSame(0, $state['attemptsUsed'], 'a letter that lands is progress, not an attempt');
        self::assertSame(7, $state['hangman']['livesLeft']);
        self::assertSame([], $state['hangman']['wrong']);
        self::assertContains('F', $state['hangman']['chars']);
    }

    public function testALetterAbsentFromTheTitleCostsALife(): void
    {
        $this->start('daily', 'hangman');

        $state = $this->letter('daily', 'A');

        self::assertSame(1, $state['attemptsUsed']);
        self::assertSame(6, $state['hangman']['livesLeft']);
        self::assertSame(['A'], $state['hangman']['wrong']);
    }

    public function testTheSameLetterCannotBePlayedTwice(): void
    {
        $this->start('daily', 'hangman');
        $this->letter('daily', 'A');

        $this->letter('daily', 'a');

        // Case and accent fold to the same letter, so this is the same move.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('deja propose', $this->deaccent($this->json()['error']));
    }

    public function testSomethingThatIsNotALetterIsRefused(): void
    {
        $this->start('daily', 'hangman');

        foreach (['4', '!', '', 'ab'] as $input) {
            $this->letter('daily', $input);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, "input: {$input}");
        }
    }

    public function testSpellingTheTitleOutWinsTheRun(): void
    {
        $this->start('daily', 'hangman');

        $state = [];
        foreach (['Z', 'F', 'I', 'L', 'M'] as $letter) {
            $state = $this->letter('daily', $letter);
        }

        self::assertSame('won', $state['status']);
        self::assertSame(7, $state['hangman']['livesLeft'], 'not one of those was a miss');
        self::assertNotContains(null, $state['hangman']['chars']);
        self::assertSame($this->answerId(GameMode::DAILY, GameKind::HANGMAN), $state['answer']['id']);
    }

    public function testRunningOutOfLivesLosesAndUncoversTheTitle(): void
    {
        $this->start('daily', 'hangman');

        $state = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'G', 'H'] as $letter) {
            $state = $this->letter('daily', $letter);
        }

        self::assertSame('lost', $state['status']);
        self::assertSame(0, $state['hangman']['livesLeft']);
        // Losing shows what it was hiding, rather than blanks beside the answer.
        self::assertNotContains(null, $state['hangman']['chars']);
        self::assertSame($this->answerId(GameMode::DAILY, GameKind::HANGMAN), $state['answer']['id']);

        $this->letter('daily', 'J');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testNamingTheFilmSolvesItAndNamingTheWrongOneCostsALife(): void
    {
        $this->start('daily', 'hangman');

        $wrong = $this->wrongMovieIds(GameMode::DAILY, GameKind::HANGMAN);
        $state = $this->guess('daily', $wrong[0], 'hangman');
        self::assertSame(1, $state['attemptsUsed'], 'a wrong film costs the same as a wrong letter');
        self::assertSame(6, $state['hangman']['livesLeft']);

        $state = $this->guess('daily', $this->answerId(GameMode::DAILY, GameKind::HANGMAN), 'hangman');
        self::assertSame('won', $state['status']);
        // The winning guess is in the list too, and it must not be charged for.
        self::assertSame(1, $state['attemptsUsed']);
    }

    public function testOnlyTheHangmanTakesLetters(): void
    {
        $this->start('daily', 'clue');

        $this->letter('daily', 'A', 'clue');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the other games have no such move');
    }

    /**
     * @return array<string, mixed>
     */
    private function letter(string $mode, string $value, string $game = 'hangman'): array
    {
        $this->client->request(
            'POST',
            "/api/games/{$game}/{$mode}/letter",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['letter' => $value])
        );

        return $this->json()['session'] ?? [];
    }

    public function testThePosterGameDealsPixelsInsteadOfClues(): void
    {
        $state = $this->start('daily', 'poster');

        self::assertSame('poster', $state['game']);
        self::assertSame([], $state['clues'], 'the artwork is the only thing this game hands out');
        self::assertSame(5, $state['maxAttempts'], 'three to five tries is the balance this game was tuned for');

        $poster = $state['poster'];
        self::assertNotNull($poster);
        self::assertSame(1, $poster['step']);
        self::assertSame($state['maxAttempts'], $poster['steps'], 'the rungs are the tries');
        self::assertCount($poster['width'] * $poster['height'], $poster['colors']);
        // What crosses the wire is the pixels themselves — no URL, no path, nothing to open
        // in another tab.
        self::assertSame(['width', 'height', 'step', 'steps', 'colors'], array_keys($poster));
    }

    public function testEveryGuessSharpensTheArtworkByOneRung(): void
    {
        $state = $this->start('daily', 'poster');
        $wrong = $this->wrongMovieIds(GameMode::DAILY, GameKind::POSTER);
        $width = $state['poster']['width'];

        foreach ([1, 2, 3] as $attempt) {
            $state = $this->guess('daily', $wrong[$attempt - 1], 'poster');

            self::assertSame($attempt + 1, $state['poster']['step']);
            self::assertGreaterThan($width, $state['poster']['width'], "guess {$attempt} must buy resolution");
            self::assertNull($state['answer'], 'the answer must stay hidden while the run is open');

            $width = $state['poster']['width'];
        }
    }

    public function testTheAnswerIsOnlyEverAFilmWithArtwork(): void
    {
        $this->start('daily', 'poster');

        $answer = $this->entityManager->find(Movie::class, $this->answerId(GameMode::DAILY, GameKind::POSTER));

        self::assertNotNull($answer?->getPosterPath(), 'a film with no poster cannot be the poster game');
    }

    public function testTheOpenPosterRunNeverCarriesTheFilmItHides(): void
    {
        $this->start('daily', 'poster');
        $this->guess('daily', $this->aWrongMovieId(GameMode::DAILY, GameKind::POSTER), 'poster');

        $answer = $this->entityManager->find(Movie::class, $this->answerId(GameMode::DAILY, GameKind::POSTER));
        self::assertNotNull($answer);
        $body = (string) $this->client->getResponse()->getContent();

        foreach ([$answer->getTitle(), (string) $answer->getPosterPath(), (string) $answer->getLetterboxdSlug()] as $secret) {
            self::assertStringNotContainsString($secret, $body, 'the payload gave the film away');
        }
    }

    public function testRunningOutOfSharpnessLosesTheRun(): void
    {
        $state = $this->start('daily', 'poster');
        $wrong = $this->wrongMovieIds(GameMode::DAILY, GameKind::POSTER);

        for ($attempt = 0; $attempt < $state['maxAttempts']; ++$attempt) {
            $state = $this->guess('daily', $wrong[$attempt], 'poster');
        }

        self::assertSame('lost', $state['status']);
        self::assertSame($this->answerId(GameMode::DAILY, GameKind::POSTER), $state['answer']['id']);
        // The reveal shows the real poster, so the grid stops at its sharpest rung instead
        // of running off the end of the ladder.
        self::assertSame($state['maxAttempts'], $state['poster']['step']);
    }

    private function start(string $mode, string $game = 'clue'): array
    {
        $this->client->request('POST', "/api/games/{$game}/{$mode}/start");
        self::assertResponseIsSuccessful();

        return $this->json()['session'];
    }

    /**
     * @return array<string, mixed>
     */
    private function guess(string $mode, string $movieId, string $game = 'clue'): array
    {
        $this->client->request(
            'POST',
            "/api/games/{$game}/{$mode}/guess",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['movieId' => $movieId])
        );

        return $this->json()['session'] ?? [];
    }

    private function answerId(GameMode $mode, GameKind $game = GameKind::CLUE): string
    {
        $session = GameMode::DAILY === $mode
            ? $this->sessions->findDaily($this->player, $game, new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            : $this->sessions->findLatestInfinite($this->player, $game);

        self::assertNotNull($session);

        return (string) $session->getMovie()->getId();
    }

    /**
     * @return list<string>
     */
    private function wrongMovieIds(GameMode $mode = GameMode::DAILY, GameKind $game = GameKind::CLUE): array
    {
        $answerId = $this->answerId($mode, $game);

        return array_values(array_filter(
            array_map(static fn (Movie $movie) => (string) $movie->getId(), $this->library),
            static fn (string $id) => $id !== $answerId
        ));
    }

    private function aWrongMovieId(GameMode $mode = GameMode::DAILY, GameKind $game = GameKind::CLUE): string
    {
        return $this->wrongMovieIds($mode, $game)[0];
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
            if ($index <= self::WITH_POSTER) {
                $movie->setPosterPath(sprintf('/zz-jeu-%02d.jpg', $index));
            }
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

    /**
     * Fills the pixel cache so the poster game never reaches for image.tmdb.org here. The
     * reduction itself is PosterPixelatorTest's subject; what this file is about is what a
     * run does with the rungs, which needs rungs to exist and not to depend on a network.
     *
     * This does mean knowing the cache key, which is the price of not stubbing the HTTP
     * client through test-only DI wiring — a worse coupling for the same result.
     */
    private function seedPosterPixels(): void
    {
        $steps = self::getContainer()->get(PosterPixelator::class)->steps();
        $cache = $this->posterCache();

        // Widths of our own, unrelated to the real ladder: the assertions below are about
        // climbing the rungs, not about how coarse any particular one is.
        $ladder = [];
        for ($rung = 0; $rung < $steps; ++$rung) {
            $width = 6 + $rung * 7;
            $height = (int) round($width * 1.5);
            $ladder[] = [
                'width' => $width,
                'height' => $height,
                'colors' => array_fill(0, $width * $height, '#c0ffee'),
            ];
        }

        foreach ($this->posterCacheKeys() as $key) {
            $item = $cache->getItem($key);
            $item->set($ladder);
            $cache->save($item);
        }
    }

    /**
     * @return list<string>
     */
    private function posterCacheKeys(): array
    {
        return array_map(
            static fn (int $index) => 'game.poster.'.md5(sprintf('/zz-jeu-%02d.jpg', $index)),
            range(1, self::WITH_POSTER)
        );
    }

    private function posterCache(): CacheItemPoolInterface
    {
        return self::getContainer()->get('cache.app');
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
