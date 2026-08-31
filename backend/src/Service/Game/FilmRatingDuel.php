<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\DuelBoardDto;
use App\DTO\Game\DuelCardDto;
use App\DTO\Game\DuelRoundDto;
use App\Entity\Enum\GameStatus;
use App\Entity\GameSession;
use App\Entity\Movie;
use App\Entity\User;
use App\Mapper\MovieMapper;
use App\Repository\GameSessionRepository;
use App\Repository\MovieRepository;

/**
 * "Lequel as-tu mieux noté ?" — the one game that hides nothing.
 *
 * The other seven test how well you know films. This one tests how well you know your own
 * verdicts, which turns out to be a different thing: a rating given eighteen months ago on
 * a Tuesday is not something anybody remembers, and the ranking it implies is not one you
 * ever sat down and made. That is why it is played as a streak rather than in rounds — the
 * interest is in how far you get before your library disagrees with you.
 *
 * The rating is read through MovieMapper, so it is the same average the film's own card
 * shows: a film watched twice and rated differently has one standing verdict here too.
 */
final class FilmRatingDuel
{
    /** How many settled rounds a finished board shows, newest last. */
    private const HISTORY = 12;

    public function __construct(
        private readonly MovieRepository $movies,
        private readonly GameSessionRepository $sessions,
        private readonly MovieMapper $movieMapper,
    ) {
    }

    /**
     * The next pair, or an empty array when the library has nothing left to field.
     *
     * @param list<string> $excludeIds films already on the table in this run
     *
     * @return list<Movie>
     */
    public function draw(User $user, string $seed, array $excludeIds = []): array
    {
        return $this->movies->findDuelPair($user, $seed, $excludeIds);
    }

    /**
     * Whether the film backed is the one the profile actually rated higher.
     *
     * A pair is only ever drawn with two different averages, so there is no tie to arbitrate
     * — but the comparison is written to be total anyway rather than trusting the draw, in
     * case a rating changes under a run that is still open.
     */
    public function isRightSide(User $user, Movie $picked, Movie $other): bool
    {
        return ($this->ratingOf($user, $picked) ?? -1.0) > ($this->ratingOf($user, $other) ?? -1.0);
    }

    /**
     * The board as the player may see it.
     *
     * The pair on the table crosses the wire without its ratings — those are the answer.
     * Settled rounds carry theirs, since the point of keeping a history is being able to
     * look back at what your own numbers said.
     */
    public function board(GameSession $session, bool $isOver): DuelBoardDto
    {
        $user = $session->getUser();
        $picks = $session->getGuesses();
        $plays = $session->getPlays();

        // Every pick before the last one was right — a wrong one ends the run there and
        // then — so the streak needs no re-judging, only the last round's verdict.
        $streak = \count($picks) - ($this->lastRoundWasLost($session, $isOver) ? 1 : 0);

        $history = [];
        foreach (\array_slice($plays, -self::HISTORY, null, true) as $index => $pair) {
            $picked = $picks[$index] ?? null;
            if (null === $picked || 2 !== \count($pair)) {
                continue;
            }

            $cards = $this->cards($user, $pair, withRatings: true);
            if (2 !== \count($cards)) {
                // A film left the library between the round and this read.
                continue;
            }

            $history[] = new DuelRoundDto(
                cards: $cards,
                pickedId: $picked,
                // The round is won when the pick carries the higher of the two ratings now
                // on the cards, which is the same judgement made when it was played.
                correct: $this->pickWasRight($cards, $picked),
            );
        }

        // A run that was given up keeps its pair on the table, and keeping it is the whole
        // point: the answer to "lequel as-tu noté le plus haut" is the two ratings, so this
        // is the one state where the cards carry them before a pick. Every other ending
        // clears the table — there is no next pair, and a dead one left standing invites a
        // click that cannot land.
        $givenUp = GameStatus::REVEALED === $session->getStatus();

        return new DuelBoardDto(
            cards: $isOver && !$givenUp
                ? null
                : $this->cards($user, $session->getBoard(), withRatings: $givenUp),
            history: $history,
            streak: max(0, $streak),
            best: $this->sessions->bestStreak($user, $session->getGame(), $session->getMode()),
        );
    }

    /**
     * @param list<string> $movieIds
     *
     * @return list<DuelCardDto>
     */
    private function cards(User $user, array $movieIds, bool $withRatings): array
    {
        $cards = [];
        foreach ($this->movies->findByIdsOrdered($movieIds) as $movie) {
            $summary = $this->movieMapper->toSummaryDto($movie, $user);

            $cards[] = new DuelCardDto(
                movieId: $summary->id,
                title: $summary->title,
                releaseYear: $summary->releaseYear,
                posterUrl: $summary->posterUrl,
                // The summary always knows the rating; whether it is allowed out is this
                // class's decision, and this is the only line that makes it.
                rating: $withRatings ? $summary->myAverageRating : null,
            );
        }

        return $cards;
    }

    /**
     * @param list<DuelCardDto> $cards exactly two
     */
    private function pickWasRight(array $cards, string $pickedId): bool
    {
        [$left, $right] = $cards;
        $picked = $left->movieId === $pickedId ? $left : $right;
        $other = $left->movieId === $pickedId ? $right : $left;

        return ($picked->rating ?? -1.0) > ($other->rating ?? -1.0);
    }

    private function lastRoundWasLost(GameSession $session, bool $isOver): bool
    {
        // A run can also end by running the library dry, and that last pick was a good one.
        return $isOver && GameStatus::LOST === $session->getStatus();
    }

    private function ratingOf(User $user, Movie $movie): ?float
    {
        return $this->movieMapper->toSummaryDto($movie, $user)->myAverageRating;
    }
}
