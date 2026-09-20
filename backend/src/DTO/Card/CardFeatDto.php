<?php

declare(strict_types=1);

namespace App\DTO\Card;

use App\Entity\Enum\CardFeat;
use App\Entity\Enum\CardFeatFamily;

/**
 * One card feat, at the rung it currently stands on.
 *
 * Derived and stored nowhere, exactly as a trophy is — which is what lets the frontend reuse
 * the trophy shelf's locked/unlocked vocabulary rather than inventing a third one. The names
 * and the jokes live with the French copy in the client; what travels is the key and the
 * numbers.
 */
final readonly class CardFeatDto
{
    public function __construct(
        public CardFeat $key,
        public CardFeatFamily $family,
        /** 0 means not yet won. */
        public int $level,
        /** Where the tally stands, in whatever the feat counts. */
        public int $value,
        /** The rung currently stood on, or the first one while level is 0. */
        public int $currentTier,
        /** The next rung, or null at the top of the ladder. */
        public ?int $nextTier,
    ) {
    }
}
