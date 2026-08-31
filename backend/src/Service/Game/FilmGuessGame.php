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
use Symfony\Component\Uid\Uuid;

/**
 * The engine every game runs on.
 *
 * Eight of them now, and what they have in common is not the puzzle — it is the run: two
 * modes, a seeded draw, one board per day or one per click, a status that closes exactly
 * once. That is what lives here. What a move *means* lives in the small classes this one
 * leans on (FilmClueBuilder, FilmComparisonBuilder, ArtworkPixelator, FilmTitleHangman,
 * FilmRatingDuel, FilmReleaseTimeline), and toState() is where the two are joined.
 *
 * Six games hide one film and are played by naming films. The other two hide nothing: the
 * duel asks which of two you rated higher, the timeline asks what order five came out in.
 * They still need a `movie` because the column is not nullable, so they store the first
 * film of their board there — which is why `answer` below is withheld from them even after
 * they end. It would not be an answer, only an implementation detail.
 *
 * The one rule the whole file exists to keep: nothing the player has not earned crosses the
 * wire. toState() is the only place allowed to decide what may be seen, so that rule has
 * exactly one home.
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
        private readonly ArtworkPixelator $pixelator,
        private readonly FilmTitleHangman $hangman,
        private readonly FilmRatingDuel $duel,
        private readonly FilmReleaseTimeline $timeline,
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

    /**
     * Naming a film — the move six of the eight are played with.
     */
    public function guess(User $user, GameSession $session, string $movieId): GameSession
    {
        if (!$session->getGame()->isNamedByFilm()) {
            throw new GameException('Ce jeu ne se joue pas en nommant un film.');
        }

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
     * Duel only: backing one of the two films on the table.
     *
     * This is the one move that does not go through settle(). A streak has no attempt budget
     * to run down — a right answer is not progress towards an end, it *is* the game
     * continuing, and it draws the next pair here rather than ending anything. There are
     * only two ways out: a wrong pick, or a library with no unplayed pair left in it, and
     * the second is a win because nothing was got wrong to reach it.
     */
    public function pick(User $user, GameSession $session, string $movieId): GameSession
    {
        if (GameKind::DUEL !== $session->getGame()) {
            throw new GameException('Ce jeu ne se joue pas en duel.');
        }

        $this->assertOpen($session);

        $board = $session->getBoard();
        if (!\in_array($movieId, $board, true)) {
            throw new GameException('Ce film n\'est pas dans le duel en cours.');
        }

        $pair = $this->movies->findByIdsOrdered($board);
        if (2 !== \count($pair)) {
            throw new GameException('Ce duel n\'est plus jouable.');
        }

        [$left, $right] = $pair;
        $backedLeft = (string) $left->getId() === $movieId;

        $session->addPlay($board)->addGuess($movieId);

        if (!$this->duel->isRightSide($user, $backedLeft ? $left : $right, $backedLeft ? $right : $left)) {
            $this->close($session, GameStatus::LOST);

            return $session;
        }

        // Seeded off the session and the round rather than off random bytes, so replaying a
        // run against the same library deals the same duels — which is what makes this
        // testable at all.
        $next = $this->duel->draw(
            $user,
            sprintf('duel-%s-%d', $session->getId(), \count($session->getPlays())),
            array_merge(...$session->getPlays())
        );

        if (2 === \count($next)) {
            $session->setBoard(array_map(static fn (Movie $movie) => (string) $movie->getId(), $next));
            $this->entityManager->flush();

            return $session;
        }

        $this->close($session, GameStatus::WON);

        return $session;
    }

    /**
     * Timeline only: an ordering of every film on the board, oldest first.
     *
     * @param list<string> $order
     */
    public function order(GameSession $session, array $order): GameSession
    {
        if (GameKind::TIMELINE !== $session->getGame()) {
            throw new GameException('Ce jeu ne se joue pas dans l\'ordre.');
        }

        $this->assertOpen($session);

        $board = $session->getBoard();
        $order = array_values($order);

        // A permutation, not a subset and not a list with a film played twice — an ordering
        // that is neither would be judged slot by slot and quietly scored as if it counted.
        if (\count($order) !== \count($board) || array_unique($order) !== $order || [] !== array_diff($board, $order)) {
            throw new GameException('Propose un ordre qui contient chacun des films une fois et une seule.');
        }

        $session->addPlay($order);
        $this->settle($session, $this->timeline->isSolved($session, $order));

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

    /**
     * Ends a run and clears the table. The board is emptied rather than left standing so
     * that a finished duel cannot be clicked on; its rounds are all in `plays` anyway.
     */
    private function close(GameSession $session, GameStatus $status): void
    {
        $session->setBoard([])->finish($status);
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
        $game = $session->getGame();
        $movie = $session->getMovie();
        $isOver = $session->getStatus()->isOver();
        $attemptsUsed = $this->attemptsUsed($session);

        // Two games climb the fact ladder. The clue game has nothing else, so one rung is on
        // the table before the first guess or the opening move is blind; the tagline game
        // opens on the film's own words instead and keeps the ladder shut until the first
        // miss. The rest have no ladder at all — their guesses are the feedback.
        $clues = \in_array($game, [GameKind::CLUE, GameKind::TAGLINE], true)
            ? $this->clueBuilder->build($movie)
            : [];

        $revealed = match (true) {
            $isOver => \count($clues),
            GameKind::TAGLINE === $game => min($attemptsUsed, \count($clues)),
            default => min($attemptsUsed + 1, \count($clues)),
        };

        // Both pixel games hand out their opening rung the same way and sharpen it by one
        // with every guess spent — which is simply the count of guesses so far.
        $artwork = match ($game) {
            GameKind::POSTER => $this->pixelator->pixelate($movie, $attemptsUsed),
            GameKind::BACKDROP => $this->pixelator->pixelateBackdrop($movie, $attemptsUsed),
            default => null,
        };

        $hangman = GameKind::HANGMAN === $game
            ? $this->hangman->board(
                $movie,
                $session->getLetters(),
                $this->wrongFilmCount($session),
                // Losing shows the title it was hiding, rather than a board still full of
                // blanks next to the answer spelled out underneath it.
                $isOver
            )
            : null;

        return new GameStateDto(
            game: $game,
            mode: $session->getMode(),
            status: $session->getStatus(),
            attemptsUsed: $attemptsUsed,
            maxAttempts: $this->maxAttempts($session),
            clues: \array_slice($clues, 0, $revealed),
            guesses: $this->guessesOf($session),
            // The two boardless games keep theirs withheld for good: `movie` is the first
            // film of the board, not something the player was ever asked to find.
            answer: $isOver && $game->isNamedByFilm()
                ? $this->movieMapper->toSummaryDto($movie, $session->getUser())
                : null,
            puzzleDate: $session->getPuzzleDate()?->format('Y-m-d'),
            tagline: GameKind::TAGLINE === $game ? $movie->getTagline() : null,
            artwork: $artwork,
            hangman: $hangman,
            duel: GameKind::DUEL === $game ? $this->duel->board($session, $isOver) : null,
            timeline: GameKind::TIMELINE === $game ? $this->timeline->board($session, $isOver) : null,
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

        return $this->persist($this->open(
            $user,
            $game,
            GameMode::DAILY,
            // Each game gets its own board on the same day: the seed carries the kind.
            sprintf('daily-%s-%s-%s', $game->value, $user->getId(), $today->format('Y-m-d')),
            $today
        ));
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

        return $this->persist($this->open(
            $user,
            $game,
            GameMode::INFINITE,
            // Nothing to reproduce here, unlike the daily puzzle.
            bin2hex(random_bytes(8)),
            null,
            $this->sessions->recentAnswerIds($user, $game, GameMode::INFINITE, self::RECENT_ANSWERS)
        ));
    }

    /**
     * @param list<string> $excludeIds
     */
    private function open(
        User $user,
        GameKind $game,
        GameMode $mode,
        string $seed,
        ?\DateTimeImmutable $puzzleDate,
        array $excludeIds = [],
    ): GameSession {
        $board = $this->draw($user, $game, $seed, $excludeIds);

        // The first film doubles as the session's `movie`: for six games it is the answer,
        // for the other two it is simply what keeps a non-nullable column filled.
        $session = new GameSession($user, $game, $mode, $board[0], $puzzleDate);

        if (\count($board) > 1) {
            $session->setBoard(array_map(static fn (Movie $movie) => (string) $movie->getId(), $board));
        }

        return $session;
    }

    /**
     * The films a run opens on: one for the six games that hide a film, two for the duel,
     * five for the timeline.
     *
     * @param list<string> $excludeIds
     *
     * @return non-empty-list<Movie>
     */
    private function draw(User $user, GameKind $game, string $seed, array $excludeIds = []): array
    {
        $board = $this->drawOnce($user, $game, $seed, $excludeIds);

        // A small library runs out of unplayed answers long before it runs out of films.
        if ([] === $board && [] !== $excludeIds) {
            $board = $this->drawOnce($user, $game, $seed);
        }

        if ([] === $board) {
            throw new GameException(self::cannotPlay($game));
        }

        return $board;
    }

    /**
     * @param list<string> $excludeIds
     *
     * @return list<Movie>
     */
    private function drawOnce(User $user, GameKind $game, string $seed, array $excludeIds = []): array
    {
        return match ($game) {
            GameKind::DUEL => $this->duel->draw($user, $seed, $excludeIds),
            // Not narrowed by recent answers: a set of five is drawn from a pool of one film
            // per year, and excluding the last twenty would thin that pool for no gain.
            GameKind::TIMELINE => $this->timeline->draw($user, $seed),
            default => array_values(array_filter([$this->movies->findGuessable($user, $game, $seed, $excludeIds)])),
        };
    }

    /**
     * Why a library cannot field this game — always in terms of what is missing, since the
     * fix is an enrichment run or a wider import rather than anything on this screen.
     */
    private static function cannotPlay(GameKind $game): string
    {
        return match ($game) {
            GameKind::POSTER => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'affiche.',
            GameKind::BACKDROP => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'image de fond.',
            GameKind::HANGMAN => 'Aucun film jouable : il en faut au moins un dont le titre compte quatre lettres.',
            GameKind::TAGLINE => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'accroche.',
            GameKind::DUEL => 'Aucun duel possible : il faut au moins deux films que tu as notés différemment.',
            GameKind::TIMELINE => sprintf(
                'Aucune chronologie possible : il faut au moins %d films sortis %d années différentes.',
                FilmReleaseTimeline::SIZE,
                FilmReleaseTimeline::SIZE
            ),
            default => 'Aucun film jouable : il en faut au moins un dont TMDB connaisse l\'année, le genre, '
                .'le pays, la réalisation et au moins trois acteur·rice·s.',
        };
    }

    private function persist(GameSession $session): GameSession
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Three of the games are exactly as long as their ladder — running out of facts to
     * reveal, or of sharpness to add, is what ends them. The others get a number, and the
     * duel gets a one: it has no budget of tries, only a single life.
     */
    private function maxAttempts(GameSession $session): int
    {
        return match ($session->getGame()) {
            GameKind::CLUE => \count($this->clueBuilder->build($session->getMovie())),
            // The tagline is a rung of its own, laid down before the ladder opens.
            GameKind::TAGLINE => \count($this->clueBuilder->build($session->getMovie())) + 1,
            GameKind::POSTER, GameKind::BACKDROP => $this->pixelator->steps(),
            GameKind::COMPARE => self::COMPARISON_ATTEMPTS,
            GameKind::HANGMAN => FilmTitleHangman::LIVES,
            GameKind::DUEL => 1,
            GameKind::TIMELINE => FilmReleaseTimeline::ATTEMPTS,
        };
    }

    /**
     * How much of the run has been spent.
     *
     * In the six naming games that is simply the number of films named, except in the
     * hangman, which charges only for misses — a letter that lands is progress, not an
     * attempt — so it counts wrong letters and wrong films instead, which is what its lives
     * are. The timeline spends one per ordering submitted. The duel spends nothing until it
     * spends everything: its streak is not an attempt count, and the only thing it can use
     * up is its single life.
     */
    private function attemptsUsed(GameSession $session): int
    {
        return match ($session->getGame()) {
            GameKind::HANGMAN => \count($this->hangman->wrongLetters($session->getMovie(), $session->getLetters()))
                + $this->wrongFilmCount($session),
            GameKind::TIMELINE => \count($session->getPlays()),
            GameKind::DUEL => GameStatus::LOST === $session->getStatus() ? 1 : 0,
            default => \count($session->getGuesses()),
        };
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

        // The duel stores its picks here too, but a pick is one side of a pair rather than a
        // film named against an answer — it is rendered from the duel board instead.
        if ([] === $ids || !$session->getGame()->isNamedByFilm()) {
            return [];
        }

        // Only what Doctrine can bind. An id that is not a UUID cannot match a film, but
        // handing it to findBy() does not miss — DBAL throws while converting the parameter
        // and takes the whole board down with it, which is how every finished infinite run
        // of the pixel game became a 500 after the identifiers changed shape. Filtering here
        // turns that into the case the loop below already handles: a guess with no film.
        $known = array_values(array_filter($ids, static fn (string $id) => Uuid::isValid($id)));

        $byId = [];
        foreach ([] === $known ? [] : $this->movies->findBy(['id' => $known]) as $movie) {
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
