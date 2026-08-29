<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\GameSessionRepository;
use App\Service\Game\ArtworkPixelator;
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
     * How many of them TMDB has each kind of artwork for. Deliberately minorities, and
     * deliberately different ones: each pixel game may only ever draw from its own, and a
     * fixture where every film qualifies for everything could not tell them apart.
     */
    private const WITH_POSTER = 4;
    private const WITH_BACKDROP = 3;

    /** How many carry a tagline — the one thing "L'accroche" cannot be played without. */
    private const WITH_TAGLINE = 5;

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
        $this->seedArtworkPixels();
        $this->login();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        foreach ($this->artworkCacheKeys() as $key) {
            $this->artworkCache()->deleteItem($key);
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
        foreach (['clue', 'compare', 'poster', 'hangman', 'tagline', 'backdrop', 'duel', 'timeline'] as $game) {
            $this->start('daily', $game);
        }

        // Playing one must not spend another's single run for the day.
        $this->guess('daily', $this->aWrongMovieId(GameMode::DAILY, GameKind::CLUE), 'clue');

        foreach (['compare', 'poster', 'hangman', 'tagline', 'backdrop', 'duel', 'timeline'] as $game) {
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

        $artwork = $state['artwork'];
        self::assertNotNull($artwork);
        self::assertSame(1, $artwork['step']);
        self::assertSame($state['maxAttempts'], $artwork['steps'], 'the rungs are the tries');
        self::assertCount($artwork['width'] * $artwork['height'], $artwork['colors']);
        // What crosses the wire is the pixels themselves — no URL, no path, nothing to open
        // in another tab.
        self::assertSame(['width', 'height', 'step', 'steps', 'colors'], array_keys($artwork));
    }

    public function testEveryGuessSharpensTheArtworkByOneRung(): void
    {
        $state = $this->start('daily', 'poster');
        $wrong = $this->wrongMovieIds(GameMode::DAILY, GameKind::POSTER);
        $width = $state['artwork']['width'];

        foreach ([1, 2, 3] as $attempt) {
            $state = $this->guess('daily', $wrong[$attempt - 1], 'poster');

            self::assertSame($attempt + 1, $state['artwork']['step']);
            self::assertGreaterThan($width, $state['artwork']['width'], "guess {$attempt} must buy resolution");
            self::assertNull($state['answer'], 'the answer must stay hidden while the run is open');

            $width = $state['artwork']['width'];
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
        self::assertSame($state['maxAttempts'], $state['artwork']['step']);
    }

    public function testTheTaglineGameOpensOnTheFilmsOwnWordsAndNothingElse(): void
    {
        $state = $this->start('daily', 'tagline');

        self::assertSame('tagline', $state['game']);
        self::assertNotNull($state['tagline']);
        // The tagline is the opening card, so the fact ladder stays shut until the first
        // miss — otherwise this game is the clue game with a sentence stapled on top.
        self::assertSame([], $state['clues'], 'the ladder must not open before a guess is spent');
        self::assertNull($state['answer']);
    }

    public function testTheTaglineIsWorthARungOfItsOwn(): void
    {
        $state = $this->start('daily', 'tagline');
        $wrong = $this->wrongMovieIds(GameMode::DAILY, GameKind::TAGLINE);

        // One more try than the clue game gets on the same ladder: the tagline is rung zero.
        self::assertSame(\count(self::CLUE_ORDER) + 1, $state['maxAttempts']);

        $state = $this->guess('daily', $wrong[0], 'tagline');

        self::assertCount(1, $state['clues'], 'the first miss opens the ladder');
        self::assertSame(self::CLUE_ORDER[0], $state['clues'][0]['label']);
    }

    public function testTheTaglineGameOnlyEverDrawsAFilmThatHasOne(): void
    {
        $this->start('daily', 'tagline');

        $answer = $this->entityManager->find(Movie::class, $this->answerId(GameMode::DAILY, GameKind::TAGLINE));
        self::assertNotNull($answer);
        self::assertNotSame('', (string) $answer->getTagline(), 'a film with no tagline cannot be the answer');
    }

    public function testTheBackdropGameDealsPixelsAndNoTitle(): void
    {
        $state = $this->start('daily', 'backdrop');

        self::assertSame('backdrop', $state['game']);
        self::assertSame([], $state['clues']);
        self::assertNull($state['tagline'], 'only one game deals a tagline');

        $artwork = $state['artwork'];
        self::assertNotNull($artwork);
        self::assertSame(1, $artwork['step']);
        self::assertSame($state['maxAttempts'], $artwork['steps'], 'the rungs are the tries');
        self::assertCount($artwork['width'] * $artwork['height'], $artwork['colors']);
    }

    public function testTheBackdropGameOnlyEverDrawsAFilmThatHasOne(): void
    {
        $this->start('daily', 'backdrop');

        $answer = $this->entityManager->find(Movie::class, $this->answerId(GameMode::DAILY, GameKind::BACKDROP));
        self::assertNotNull($answer);
        // Carrying a poster is not carrying a backdrop, and the fixture keeps the two sets
        // different on purpose so this assertion can fail.
        self::assertNotNull($answer->getBackdropPath(), 'a film with no backdrop cannot be the answer');
    }

    public function testTheDuelDealsTwoFilmsWithoutTheirRatings(): void
    {
        $state = $this->start('daily', 'duel');

        self::assertSame('duel', $state['game']);
        self::assertSame([], $state['guesses'], 'a pick is not a film named against an answer');
        self::assertSame(0, $state['duel']['streak']);
        self::assertCount(2, $state['duel']['cards']);

        foreach ($state['duel']['cards'] as $card) {
            // The rating *is* the answer here, so it must not be on the table.
            self::assertNull($card['rating'], 'the verdict must not cross the wire while the run is open');
        }
    }

    public function testABackedFilmThatWasRatedHigherKeepsTheStreakGoing(): void
    {
        $this->start('daily', 'duel');
        [$higher] = $this->duelSides();

        $state = $this->pick('daily', $higher);

        self::assertSame('in_progress', $state['status'], 'a right answer is not an end');
        self::assertSame(1, $state['duel']['streak']);
        self::assertCount(2, $state['duel']['cards'], 'the next pair is already on the table');

        // The settled round comes back with its numbers, which is the point of a history.
        self::assertCount(1, $state['duel']['history']);
        self::assertTrue($state['duel']['history'][0]['correct']);
        self::assertNotNull($state['duel']['history'][0]['cards'][0]['rating']);
    }

    public function testOneWrongSideEndsTheRunAndTheStreakStopsBeforeIt(): void
    {
        $this->start('daily', 'duel');
        [, $lower] = $this->duelSides();

        $state = $this->pick('daily', $lower);

        self::assertSame('lost', $state['status']);
        self::assertSame(0, $state['duel']['streak'], 'the losing round is not part of the streak');
        self::assertNull($state['duel']['cards'], 'a finished duel leaves nothing to click');
        self::assertNull($state['answer'], 'the duel never had an answer to reveal');
    }

    public function testADuelRunsUntilTheLibraryHasNoPairLeft(): void
    {
        $this->start('daily', 'duel');

        // Only WITH_POSTER films can be drawn, two go per round, and a film is never played
        // twice in a run — so a perfect run ends by running the table dry rather than by
        // hitting a limit. That is a win: nothing was got wrong to reach it.
        $rounds = 0;
        do {
            [$higher] = $this->duelSides();
            $state = $this->pick('daily', $higher);
            ++$rounds;
        } while ('in_progress' === $state['status'] && $rounds < self::LIBRARY_SIZE);

        self::assertSame('won', $state['status']);
        self::assertSame(intdiv(self::WITH_POSTER, 2), $rounds);
        self::assertSame($rounds, $state['duel']['streak']);
    }

    public function testAPickOutsideTheCurrentPairIsRefused(): void
    {
        $this->start('daily', 'duel');
        $onTheTable = $this->duelBoard();

        $elsewhere = array_values(array_filter(
            array_map(static fn (Movie $movie) => (string) $movie->getId(), $this->library),
            static fn (string $id) => !\in_array($id, $onTheTable, true)
        ));

        $this->client->request(
            'POST',
            '/api/games/duel/daily/pick',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['movieId' => $elsewhere[0]])
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheDuelCannotBePlayedByNamingAFilm(): void
    {
        $this->start('daily', 'duel');
        [$higher] = $this->duelSides();

        // /guess and /pick take the same payload and mean different things by it. Sending a
        // pick to the wrong door must not be judged as if it were a guess — and the refusal
        // is the engine's rather than the router's, so it can say why in words.
        $this->guess('daily', $higher, 'duel');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Ce jeu ne se joue pas en nommant un film.', $this->json()['error']);
        self::assertSame(0, $this->duelState()['duel']['streak'], 'a refused move must not count as a round');
    }

    public function testTheTimelineDealsFiveFilmsWithoutTheirYears(): void
    {
        $state = $this->start('daily', 'timeline');

        self::assertSame('timeline', $state['game']);
        self::assertSame(3, $state['maxAttempts']);
        self::assertCount(5, $state['timeline']['cards']);
        self::assertSame([], $state['timeline']['attempts']);
        self::assertNull($state['timeline']['solution']);

        foreach ($state['timeline']['cards'] as $card) {
            self::assertNull($card['releaseYear'], 'the year is the answer and must not be dealt with the card');
        }
    }

    public function testAWrongOrderingIsAnsweredWithWhichSlotsWereRightAndNothingMore(): void
    {
        $this->start('daily', 'timeline');
        $solution = $this->timelineSolution();

        // Two neighbours swapped: three slots stand, two do not.
        $attempt = $solution;
        [$attempt[0], $attempt[1]] = [$attempt[1], $attempt[0]];

        $state = $this->order('daily', $attempt);

        self::assertSame('in_progress', $state['status']);
        self::assertCount(1, $state['timeline']['attempts']);
        self::assertSame([false, false, true, true, true], $state['timeline']['attempts'][0]['correct']);
        self::assertSame(3, $state['timeline']['attempts'][0]['correctCount']);
        self::assertNull($state['timeline']['solution'], 'a near miss is still not the answer');
    }

    public function testTheRightOrderingWinsAndRevealsTheYears(): void
    {
        $this->start('daily', 'timeline');

        $state = $this->order('daily', $this->timelineSolution());

        self::assertSame('won', $state['status']);
        self::assertSame($this->timelineSolution(), $state['timeline']['solution']);

        foreach ($state['timeline']['cards'] as $card) {
            self::assertNotNull($card['releaseYear'], 'a finished board shows what it was hiding');
        }
    }

    public function testThreeWrongOrderingsEndTheRun(): void
    {
        $this->start('daily', 'timeline');
        $solution = $this->timelineSolution();

        $wrong = $solution;
        [$wrong[0], $wrong[1]] = [$wrong[1], $wrong[0]];

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $state = $this->order('daily', $wrong);
            self::assertSame($attempt, $state['attemptsUsed']);
        }

        self::assertSame('lost', $state['status']);
        self::assertSame($solution, $state['timeline']['solution']);
    }

    public function testAnOrderingThatIsNotAPermutationOfTheBoardIsRefused(): void
    {
        $this->start('daily', 'timeline');
        $solution = $this->timelineSolution();

        // The same film twice: judged slot by slot this would score, which is exactly why
        // the shape is checked before anything is judged.
        $this->order('daily', [$solution[0], $solution[0], $solution[2], $solution[3], $solution[4]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testEveryFilmOnATimelineBoardComesFromADifferentYear(): void
    {
        $this->start('daily', 'timeline');

        $years = array_map(
            fn (string $id) => $this->entityManager->find(Movie::class, $id)?->getReleaseYear(),
            $this->timelineBoard()
        );

        // Two films sharing a year would leave the puzzle with two right answers and mark
        // one of them wrong.
        self::assertSame($years, array_values(array_unique($years)));
    }

    public function testSeriesAreNeverDrawnByAnyGame(): void
    {
        $series = new Movie('zz-jeu-serie', 'ZZ Serie');
        $series->setMediaType(MediaType::SERIES);
        $series->setReleaseYear(1999);
        $series->setPosterPath('/zz-jeu-serie.jpg');
        $series->setBackdropPath('/zz-decor-serie.jpg');
        $series->setTagline('ZZ Accroche serie');
        foreach ($this->library[0]->getGenres() as $genre) {
            $series->addGenre($genre);
        }
        foreach ($this->library[0]->getCountries() as $country) {
            $series->addCountry($country);
        }
        $this->entityManager->persist($series);

        $this->credit($series, 'ZZ Real serie', CreditRole::DIRECTOR, null);
        foreach ([0, 1, 2] as $order) {
            $this->credit($series, sprintf('ZZ Acteur serie-%d', $order), CreditRole::ACTOR, $order);
        }

        $watch = new Watch($this->player, $series, WatchSource::MANUAL);
        $watch->setWatchedDate(new \DateTimeImmutable('2024-01-01'));
        $watch->setRating(5.0);
        $this->entityManager->persist($watch);
        $this->entityManager->flush();

        $seriesId = (string) $series->getId();

        // It qualifies for every game on paper — artwork, tagline, credits, a rating of its
        // own — and is still never dealt, because a season count is not a runtime and a
        // heading that promises a film should be telling the truth.
        foreach ([GameKind::CLUE, GameKind::POSTER, GameKind::HANGMAN, GameKind::TAGLINE, GameKind::BACKDROP] as $game) {
            $this->start('infinite', $game->value);
            self::assertNotSame($seriesId, $this->answerId(GameMode::INFINITE, $game), $game->value);
        }

        $this->start('infinite', 'duel');
        self::assertNotContains($seriesId, $this->duelBoard(GameMode::INFINITE));

        $this->start('infinite', 'timeline');
        self::assertNotContains($seriesId, $this->timelineBoard(GameMode::INFINITE));
    }

    /**
     * @return array<string, mixed>
     */
    private function pick(string $mode, string $movieId): array
    {
        $this->client->request(
            'POST',
            "/api/games/duel/{$mode}/pick",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['movieId' => $movieId])
        );

        return $this->json()['session'] ?? [];
    }

    /**
     * @param list<string> $order
     *
     * @return array<string, mixed>
     */
    private function order(string $mode, array $order): array
    {
        $this->client->request(
            'POST',
            "/api/games/timeline/{$mode}/order",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['order' => $order])
        );

        return $this->json()['session'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function duelState(): array
    {
        $this->client->request('GET', '/api/games/duel/daily');

        return $this->json()['session'];
    }

    /**
     * The pair on the table, read from the session rather than from the response — the
     * response is where the ratings are deliberately missing.
     *
     * @return list<string>
     */
    private function duelBoard(GameMode $mode = GameMode::DAILY): array
    {
        return $this->boardOf($mode, GameKind::DUEL);
    }

    /**
     * @return list<string>
     */
    private function timelineBoard(GameMode $mode = GameMode::DAILY): array
    {
        return $this->boardOf($mode, GameKind::TIMELINE);
    }

    /**
     * @return list<string>
     */
    private function boardOf(GameMode $mode, GameKind $game): array
    {
        $session = GameMode::DAILY === $mode
            ? $this->sessions->findDaily($this->player, $game, new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            : $this->sessions->findLatestInfinite($this->player, $game);

        self::assertNotNull($session);

        return $session->getBoard();
    }

    /**
     * The current duel's two films, better-rated first.
     *
     * @return array{string, string}
     */
    private function duelSides(): array
    {
        $board = $this->duelBoard();
        self::assertCount(2, $board);

        $ratings = [];
        foreach ($board as $id) {
            $movie = $this->entityManager->find(Movie::class, $id);
            self::assertNotNull($movie);
            $ratings[$id] = $movie->getWatches()->first()->getRating();
        }

        arsort($ratings);
        $ids = array_keys($ratings);

        return [$ids[0], $ids[1]];
    }

    /**
     * The board in true release order — what a winning move looks like.
     *
     * @return list<string>
     */
    private function timelineSolution(): array
    {
        $years = [];
        foreach ($this->timelineBoard() as $id) {
            $movie = $this->entityManager->find(Movie::class, $id);
            self::assertNotNull($movie);
            $years[$id] = $movie->getReleaseYear();
        }

        asort($years);

        return array_keys($years);
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
            if ($index <= self::WITH_BACKDROP) {
                $movie->setBackdropPath(sprintf('/zz-decor-%02d.jpg', $index));
            }
            if ($index <= self::WITH_TAGLINE) {
                $movie->setTagline(sprintf('ZZ Accroche %02d', $index));
            }
            $this->entityManager->persist($movie);

            $this->credit($movie, sprintf('ZZ Real %02d', $index), CreditRole::DIRECTOR, null);
            // Three billed actors is the floor the picker requires for a playable film.
            foreach ([0, 1, 2] as $order) {
                $this->credit($movie, sprintf('ZZ Acteur %02d-%d', $index, $order), CreditRole::ACTOR, $order);
            }

            $watch = new Watch($this->player, $movie, WatchSource::MANUAL);
            $watch->setWatchedDate(new \DateTimeImmutable('2024-01-01'));
            // Every film a different rating, which is what the duel needs to have a right
            // answer at all — a library rated flat has no pair to draw.
            $watch->setRating(min(5.0, 0.5 * $index));
            $this->entityManager->persist($watch);

            $this->library[] = $movie;
        }

        $this->entityManager->flush();
    }

    /**
     * Fills the pixel cache so neither pixel game reaches for image.tmdb.org here. The
     * reduction itself is ArtworkPixelatorTest's subject; what this file is about is what a
     * run does with the rungs, which needs rungs to exist and not to depend on a network.
     *
     * This does mean knowing the cache key, which is the price of not stubbing the HTTP
     * client through test-only DI wiring — a worse coupling for the same result.
     */
    private function seedArtworkPixels(): void
    {
        $steps = self::getContainer()->get(ArtworkPixelator::class)->steps();
        $cache = $this->artworkCache();

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

        foreach ($this->artworkCacheKeys() as $key) {
            $item = $cache->getItem($key);
            $item->set($ladder);
            $cache->save($item);
        }
    }

    /**
     * @return list<string>
     */
    private function artworkCacheKeys(): array
    {
        // One key per image *and per source width*: the two ladders are cached apart so a
        // poster's rungs can never be served to the game asking for a backdrop.
        return array_merge(
            array_map(
                static fn (int $index) => 'game.artwork.w342.'.md5(sprintf('/zz-jeu-%02d.jpg', $index)),
                range(1, self::WITH_POSTER)
            ),
            array_map(
                static fn (int $index) => 'game.artwork.w780.'.md5(sprintf('/zz-decor-%02d.jpg', $index)),
                range(1, self::WITH_BACKDROP)
            )
        );
    }

    private function artworkCache(): CacheItemPoolInterface
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
