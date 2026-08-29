<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * One side of a duel, deliberately thinner than MovieSummaryDto.
 *
 * The summary carries myAverageRating, which in this game *is* the answer — sending it
 * would put the verdict in the network tab before the player has clicked anything. So the
 * duel gets a card of its own holding only what has to be drawn, and the rating appears
 * exactly once: on a round that has already been resolved.
 */
final readonly class DuelCardDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        /** The player's own average, filled in only once the round is over. */
        public ?float $rating = null,
    ) {
    }
}
