<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\TimelineAttemptDto;
use App\DTO\Game\TimelineBoardDto;
use App\DTO\Game\TimelineCardDto;
use App\Entity\GameSession;
use App\Entity\Movie;
use App\Entity\User;
use App\Mapper\MovieMapper;
use App\Repository\MovieRepository;

/**
 * "Remets-les dans l'ordre" — five films, oldest first.
 *
 * The odd one out of the eight, and deliberately so: nothing is hidden, nothing is guessed.
 * You are handed everything at once and asked to reason about it, which is a different kind
 * of pleasure from recall — closer to a crossword than to a quiz. It is also the only game
 * whose difficulty comes from the *set* rather than from any one film: five decades apart is
 * trivial and five from the 2010s is brutal, and both come out of the same draw.
 *
 * What is said back about an attempt is one bit per slot: right, or not. Not which film
 * belongs there and not whether one is too early — either would collapse the puzzle in a
 * single move, and three attempts would be two too many.
 */
final class FilmReleaseTimeline
{
    /** How many films are dealt. Five is enough to need reasoning, few enough to hold. */
    public const SIZE = 5;

    /** How many orderings may be submitted before the run is lost. */
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly MovieRepository $movies,
        private readonly MovieMapper $movieMapper,
    ) {
    }

    /**
     * The films to deal, in the order they should be shown — which is the draw order, and
     * therefore unrelated to the answer.
     *
     * @return list<Movie> exactly SIZE of them, or empty when the library is too thin
     */
    public function draw(User $user, string $seed): array
    {
        return $this->movies->findTimelineSet($user, $seed, self::SIZE);
    }

    /**
     * Whether a submitted ordering is the right one.
     *
     * @param list<string> $order film ids, oldest first
     */
    public function isSolved(GameSession $session, array $order): bool
    {
        return $order === $this->solution($session);
    }

    /**
     * The films on the board sorted by release year, oldest first.
     *
     * Years are distinct by construction — findTimelineSet draws one film per year — so this
     * is a total order and there is exactly one right answer. The id is the tie-breaker only
     * so that a set drawn before that guarantee existed still sorts deterministically rather
     * than depending on however the rows came back.
     *
     * @return list<string>
     */
    public function solution(GameSession $session): array
    {
        $byId = [];
        foreach ($this->movies->findByIdsOrdered($session->getBoard()) as $movie) {
            $byId[(string) $movie->getId()] = $movie->getReleaseYear() ?? PHP_INT_MAX;
        }

        $ids = array_keys($byId);
        usort($ids, static fn (string $a, string $b) => [$byId[$a], $a] <=> [$byId[$b], $b]);

        return $ids;
    }

    /**
     * The board as the player may see it.
     */
    public function board(GameSession $session, bool $isOver): TimelineBoardDto
    {
        $solution = $this->solution($session);

        return new TimelineBoardDto(
            cards: $this->cards($session->getUser(), $session->getBoard(), $isOver),
            attempts: $this->attempts($session, $solution),
            solution: $isOver ? $solution : null,
        );
    }

    /**
     * @param list<string> $movieIds
     *
     * @return list<TimelineCardDto>
     */
    private function cards(User $user, array $movieIds, bool $isOver): array
    {
        $cards = [];
        foreach ($this->movies->findByIdsOrdered($movieIds) as $movie) {
            $summary = $this->movieMapper->toSummaryDto($movie, $user);

            $cards[] = new TimelineCardDto(
                movieId: $summary->id,
                title: $summary->title,
                posterUrl: $summary->posterUrl,
                // The year *is* the answer, so it is withheld exactly as long as the run.
                releaseYear: $isOver ? $summary->releaseYear : null,
            );
        }

        return $cards;
    }

    /**
     * @param list<string> $solution
     *
     * @return list<TimelineAttemptDto>
     */
    private function attempts(GameSession $session, array $solution): array
    {
        $attempts = [];
        foreach ($session->getPlays() as $order) {
            $correct = array_map(
                static fn (int $slot) => ($order[$slot] ?? null) === ($solution[$slot] ?? null),
                array_keys($order)
            );

            $attempts[] = new TimelineAttemptDto(
                order: array_values($order),
                correct: array_values($correct),
                correctCount: \count(array_filter($correct)),
            );
        }

        return $attempts;
    }
}
