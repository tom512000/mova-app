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
 * "Guess the film I watched", in both of its flavours: one drip-feeds clues about the
 * answer, the other lays each guess beside it attribute by attribute. The board, the
 * bookkeeping and the two modes are identical — only what a guess reveals differs, which
 * is why they share this class and split at toState().
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

    public function guess(User $user, GameSession $session, int $movieId): GameSession
    {
        if ($session->getStatus()->isOver()) {
            throw new GameException('Cette partie est déjà terminée.');
        }

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

        if ($movieId === $session->getMovie()->getId()) {
            $session->finish(GameStatus::WON);
        } elseif (\count($session->getGuesses()) >= $this->maxAttempts($session)) {
            $session->finish(GameStatus::LOST);
        }

        $this->entityManager->flush();

        return $session;
    }

    public function toState(GameSession $session): GameStateDto
    {
        $isOver = $session->getStatus()->isOver();
        $attemptsUsed = \count($session->getGuesses());
        $isClueGame = GameKind::CLUE === $session->getGame();

        $clues = $isClueGame ? $this->clueBuilder->build($session->getMovie()) : [];

        // In the clue game one fact is on the table before the first guess, otherwise the
        // opening move is blind; from there each wrong guess turns over the next. The
        // comparison game has nothing to hand out up front — the guesses are the feedback.
        $revealed = $isOver ? \count($clues) : min($attemptsUsed + 1, \count($clues));

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
            // The two games get different answers on the same day: the seed carries the kind.
            $this->pick($user, sprintf('daily-%s-%d-%s', $game->value, $user->getId(), $today->format('Y-m-d'))),
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
                // Nothing to reproduce here, unlike the daily puzzle.
                bin2hex(random_bytes(8)),
                $this->sessions->recentAnswerIds($user, $game, GameMode::INFINITE, self::RECENT_ANSWERS)
            )
        );

        return $this->persist($session);
    }

    /**
     * @param list<int> $excludeIds
     */
    private function pick(User $user, string $seed, array $excludeIds = []): Movie
    {
        $movie = $this->movies->findGuessable($user, $seed, $excludeIds);

        // A small library runs out of unseen answers long before it runs out of films.
        if (null === $movie && [] !== $excludeIds) {
            $movie = $this->movies->findGuessable($user, $seed);
        }

        if (null === $movie) {
            throw new GameException(
                'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'année, le genre, '
                .'le pays, la réalisation et au moins trois acteur·rice·s.'
            );
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
     * The clue game is exactly as long as its ladder — running out of facts to reveal is
     * what ends it. The comparison game has no such natural stop, so it gets a number.
     */
    private function maxAttempts(GameSession $session): int
    {
        return GameKind::CLUE === $session->getGame()
            ? \count($this->clueBuilder->build($session->getMovie()))
            : self::COMPARISON_ATTEMPTS;
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
            $byId[$movie->getId()] = $movie;
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
                correct: $id === $answer->getId(),
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
