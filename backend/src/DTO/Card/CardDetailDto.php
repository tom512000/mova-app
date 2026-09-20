<?php

declare(strict_types=1);

namespace App\DTO\Card;

/**
 * One card, with the numbers that explain why it is worth what it is worth.
 *
 * The rank and the percentile are here for a specific reason rather than for completeness.
 * Rarity is a percentile over a score nobody can see, and a person's score comes entirely
 * from their footprint in this library — so a beloved director of one obscure film can
 * outrank a household name. That is arguably right for a collection built out of somebody's
 * own library, and it will still look wrong the first time it happens. Printing the standing
 * makes it explicable instead of arbitrary.
 */
final readonly class CardDetailDto
{
    public function __construct(
        public CardDto $card,
        /** 0 to 100. */
        public float $score,
        /** 1..N over the whole catalogue, best shelf first. */
        public int $catalogueRank,
        public ?string $firstOwnedAt,
        /**
         * The frozen tier and the live one disagree: the library grew and the card was
         * re-valued. Sent rather than hidden — the face says so, because a collection that
         * quietly downgraded what somebody owns would be worse than one that admits the
         * catalogue moved.
         */
        public bool $revalued,
    ) {
    }
}
