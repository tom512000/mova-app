<?php

declare(strict_types=1);

namespace App\DTO\Card;

use App\Entity\Enum\CardSetFamily;

/**
 * A set to complete: every work card sharing a decade, a genre, a country, a studio or a saga.
 *
 * Derived on every request, like a badge and for the same reason — the membership lives on
 * columns the catalogue already joins to, and a stored set table would have to be rewritten
 * by the importer, the sync and every correction. The one thing that *is* stored is whether
 * the completion bonus was taken, because that cannot be derived from anything.
 */
final readonly class CardSetDto
{
    public function __construct(
        public CardSetFamily $family,
        /** The set's identity as the grouping produced it: a decade, a name, or a UUID. */
        public string $key,
        public string $label,
        public int $total,
        public int $owned,
        /** How many of its cards are Rare or better — what the bonus mostly pays for. */
        public int $rarePlus,
        public int $ownedRarePlus,
        public bool $complete,
        public bool $claimed,
        /** What claiming it would pay, or did. */
        public int $bonus,
        public ?string $imageUrl,
    ) {
    }
}
