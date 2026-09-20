<?php

declare(strict_types=1);

namespace App\DTO\Card;

/**
 * One card as it comes out of a pack.
 *
 * Carries everything the reveal animation needs so the client never has to re-fetch
 * mid-flip — a dropped connection should cost the animation, not the cards.
 *
 * `wasGuaranteed` and `wasPity` exist for one reason: a pull the player was owed should not
 * read as luck. Being told "garantie" when the last slot delivers what the pack promised,
 * and "pitié" when a counter came due, is the difference between a system that feels fair
 * and one that feels arbitrary.
 */
final readonly class PackCardDto
{
    public function __construct(
        public CardDto $card,
        public bool $isNew,
        /** After this pull. 1 means it was new. */
        public int $copies,
        /** What the duplicate paid, after diminishing returns and the daily cap. 0 if new. */
        public int $jetons,
        public bool $wasGuaranteed,
        public bool $wasPity,
    ) {
    }
}
