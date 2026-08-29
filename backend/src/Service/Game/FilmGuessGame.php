<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\GameGuessDto;
use App\DTO\Game\GameStateDto;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\GameStatus;
use App\Entity\GameSession;
use App\Entity\Movie;
use App\Entity\User;
use App\Exception\GameException;
use App\Mapper\MovieMapper;
use App\Repository\GameSessionRepository;
use App\Repository\MovieRepository;
use App\Repository\WatchRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Guess the film I watched", in all three of its flavours: one drip-feeds clues about the
 * answer, one lays each guess beside it attribute by attribute, and one shows its poster
 * from too far away. The board, the bookkeeping and the two modes are identical — only what
 * a guess buys you differs, which is why they share this class and split at toState().
 *
 * The answer never crosses the wire while a run is open. toState() is the only place
 * allowed to decide what the player may see, so that rule has exactly one home.
 */
final class FilmGuessGame
{
    /** Tries in the comparison game, where clues do not set the length for us. */
    private const COMPARISON_ATTEMPTS = 8;

    /** Recent answers skipped in infinite mode, so it stops handing back the same films. */
    private const RECENT_ANSWERS = 20;

    /**
     * The daily puzzle turns over at midnight in Paris rather than wherever the server
     * happens to be, so "aujourd'hui" means the player's today.
     */
    private const PUZZLE_TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameSessionRepository $sessions,
        private readonly MovieRepository $movies,
        private readonly WatchRepository $watches,
        private readonly MovieMapper $movieMapper,
        private readonly FilmClueBuilder $clueBuilder,
        private readonly FilmComparisonBuilder $comparisonBuilder,
        private readonly PosterPixelator $pixelator,
        private readonly FilmTitleHangman $hangman,
    ) {
    }

    /**
     * The run to display on arrival, finished or not — nothing is created here.
     */
    public function current(User $user, GameKind $game, GameMode $mode): ?GameSession
    {
        return GameMode::DAILY === $mode
            ? $this->sessions->findDaily($user, $game, $this->today())
            : $this->sessions->findLatestInfinite($user, $game);
    }

    public function start(User $user, GameKind $game, GameMode $mode): GameSession
    {
        return GameMode::DAILY === $mode
            ? $this->startDaily($user, $game)
            : $this->startInfinite($user, $game);
    }

    public function guess(User $user, GameSession $session, string $movieId): GameSession
    {
        $this->assertOpen($session);

        if ($session->hasGuessed($movieId)) {
            throw new GameException('Tu as déjà proposé ce film.');
        }

        $movie = $this->movies->find($movieId);
        if (null === $movie || !$this->watches->hasAnyWatch($user, $movie)) {
            // The answer is always a film they have seen, so anything else is a wasted guess
            // rather than a legitimate one.
            throw new GameException('Ce film n\'est pas dans les films que tu as vus.');
        }

        $session->addGuess($movieId);
        // Both sides as strings: two Uuid objects holding the same value are not
        // `===` each other, and $movieId arrives from the request as text anyway.
        $this->settle($session, $movieId === (string) $session->getMovie()->getId());

        return $session;
    }

    /**
     * Hangman only: one letter, which is either on the board or costs a life. Naming the
     * film outright still works — that is the classic "solve the word" move, and it is the
     * shortcut a player reaches for the moment the title becomes readable.
     */
    public function guessLetter(GameSession $session, string $input): GameSession
    {
        if (GameKind::HANGMAN !== $session->getGame()) {
            throw new GameException('Ce jeu ne se joue pas à la lettre.');
        }

        $this->assertOpen($session);

        $letter = $this->hangman->normaliseLetter($input);
        if (null === $letter) {
            throw new GameException('Propose une seule lettre.');
        }

        if ($session->hasTriedLetter($letter)) {
            throw new GameException('Tu as déjà proposé cette lettre.');
        }

        $session->addLetter($letter);
        $this->settle($session, $this->hangman->isSolved($session->getMovie(), $session->getLetters()));

        return $session;
    }

    /**
     * Records the outcome of a move and closes the run if it ended one.
     */
    private function settle(GameSession $session, bool $won): void
    {
        if ($won) {
            $session->finish(GameStatus::WON);
        } elseif ($this->attemptsUsed($session) >= $this->maxAttempts($session)) {
            $session->finish(GameStatus::LOST);
        }

        $this->entityManager->flush();
    }

    private function assertOpen(GameSession $session): void
    {
        if ($session->getStatus()->isOver()) {
            throw new GameException('Cette partie est déjà terminée.');
        }
    }

    public function toState(GameSession $session): GameStateDto
    {
        $isOver = $session->getStatus()->isOver();
        $attemptsUsed = $this->attemptsUsed($session);
        $isClueGame = GameKind::CLUE === $session->getGame();

        $clues = $isClueGame ? $this->clueBuilder->build($session->getMovie()) : [];

        // In the clue game one fact is on the table before the first guess, otherwise the
        // opening move is blind; from there each wrong guess turns over the next. The
        // comparison game has nothing to hand out up front — the guesses are the feedback.
        $revealed = $isOver ? \count($clues) : min($attemptsUsed + 1, \count($clues));

        // The poster game hands out its opening rung the same way, and sharpens it by one
        // with every guess spent — which is simply the count of guesses so far.
        $poster = GameKind::POSTER === $session->getGame()
            ? $this->pixelator->pixelate($session->getMovie(), $attemptsUsed)
            : null;

        $hangman = GameKind::HANGMAN === $session->getGame()
            ? $this->hangman->board(
                $session->getMovie(),
                $session->getLetters(),
                $this->wrongFilmCount($session),
                // Losing shows the title it was hiding, rather than a board still full of
                // blanks next to the answer spelled out underneath it.
                $isOver
            )
            : null;

        return new GameStateDto(
            game: $session->getGame(),
            mode: $session->getMode(),
            status: $session->getStatus(),
            attemptsUsed: $attemptsUsed,
            maxAttempts: $this->maxAttempts($session),
            clues: \array_slice($clues, 0, $revealed),
            guesses: $this->guessesOf($session),
            answer: $isOver ? $this->movieMapper->toSummaryDto($session->getMovie(), $session->getUser()) : null,
            puzzleDate: $session->getPuzzleDate()?->format('Y-m-d'),
            poster: $poster,
            hangman: $hangman,
        );
    }

    private function startDaily(User $user, GameKind $game): GameSession
    {
        $today = $this->today();

        // One run a day, won or lost: coming back shows the result rather than a new board.
        $existing = $this->sessions->findDaily($user, $game, $today);
        if (null !== $existing) {
            return $existing;
        }

        $session = new GameSession(
            $user,
            $game,
            GameMode::DAILY,
            // Each game gets its own answer on the same day: the seed carries the kind.
            $this->pick($user, $game, sprintf('daily-%s-%s-%s', $game->value, $user->getId(), $today->format('Y-m-d'))),
            $today
        );

        return $this->persist($session);
    }

    private function startInfinite(User $user, GameKind $game): GameSession
    {
        // This method is only reached from the "nouvelle partie" button, so an unfinished
        // run is being replaced on purpose. It is dropped rather than recorded as a loss —
        // it was never played to a conclusion, and it would poison any future stats.
        $abandoned = $this->sessions->findOpenInfinite($user, $game);
        if (null !== $abandoned) {
            $this->entityManager->remove($abandoned);
            $this->entityManager->flush();
        }

        $session = new GameSession(
            $user,
            $game,
            GameMode::INFINITE,
            $this->pick(
                $user,
                $game,
                // Nothing to reproduce here, unlike the daily puzzle.
                bin2hex(random_bytes(8)),
                $this->sessions->recentAnswerIds($user, $game, GameMode::INFINITE, self::RECENT_ANSWERS)
            )
        );

        return $this->persist($session);
    }

    /**
     * @param list<string> $excludeIds
     */
    private function pick(User $user, GameKind $game, string $seed, array $excludeIds = []): Movie
    {
        $movie = $this->movies->findGuessable($user, $game, $seed, $excludeIds);

        // A small library runs out of unseen answers long before it runs out of films.
        if (null === $movie && [] !== $excludeIds) {
            $movie = $this->movies->findGuessable($user, $game, $seed);
        }

        if (null === $movie) {
            throw new GameException(match ($game) {
                GameKind::POSTER => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'affiche.',
                GameKind::HANGMAN => 'Aucun film jouable : il en faut au moins un dont le titre compte quatre lettres.',
                default => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'année, le genre, '
                    .'le pays, la réalisation et au moins trois acteur·rice·s.',
            });
        }

        return $movie;
    }

    private function persist(GameSession $session): GameSession
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Two of the games are exactly as long as their ladder — running out of facts to reveal,
     * or of sharpness to add, is what ends them. The other two have no such natural stop, so
     * they get a number.
     */
    private function maxAttempts(GameSession $session): int
    {
        return match ($session->getGame()) {
            GameKind::CLUE => \count($this->clueBuilder->build($session->getMovie())),
            GameKind::POSTER => $this->pixelator->steps(),
            GameKind::COMPARE => self::COMPARISON_ATTEMPTS,
            GameKind::HANGMAN => FilmTitleHangman::LIVES,
        };
    }

    /**
     * How much of the run has been spent.
     *
     * Everywhere but the hangman that is simply the number of films named. The hangman
     * charges only for misses — a letter that lands is progress, not an attempt — so it
     * counts wrong letters and wrong films instead, which is what its lives are.
     */
    private function attemptsUsed(GameSession $session): int
    {
        if (GameKind::HANGMAN !== $session->getGame()) {
            return \count($session->getGuesses());
        }

        return \count($this->hangman->wrongLetters($session->getMovie(), $session->getLetters()))
            + $this->wrongFilmCount($session);
    }

    private function wrongFilmCount(GameSession $session): int
    {
        $answerId = (string) $session->getMovie()->getId();

        // A winning guess is in the list too, and it never cost a life.
        return \count(array_filter(
            $session->getGuesses(),
            static fn (string $movieId) => $movieId !== $answerId
        ));
    }

    /**
     * @return list<GameGuessDto>
     */
    private function guessesOf(GameSession $session): array
    {
        $ids = $session->getGuesses();
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->movies->findBy(['id' => $ids]) as $movie) {
            // A Uuid object cannot be an array key, and the stored guesses are strings.
            $byId[(string) $movie->getId()] = $movie;
        }

        $answer = $session->getMovie();
        $isComparison = GameKind::COMPARE === $session->getGame();

        $guesses = [];
        foreach ($ids as $id) {
            $movie = $byId[$id] ?? null;
            if (null === $movie) {
                // The film left the library between the guess and this read.
                continue;
            }

            $summary = $this->movieMapper->toSummaryDto($movie, $session->getUser());
            $guesses[] = new GameGuessDto(
                movieId: $id,
                title: $summary->title,
                releaseYear: $summary->releaseYear,
                posterUrl: $summary->posterUrl,
                correct: $id === (string) $answer->getId(),
                facets: $isComparison ? $this->comparisonBuilder->compare($movie, $answer) : null,
            );
        }

        return $guesses;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::PUZZLE_TIMEZONE));
    }
}
