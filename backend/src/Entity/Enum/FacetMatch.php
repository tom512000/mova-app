<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How one attribute of a guessed film stacks up against the answer's. Drives the colour of
 * the tile, so the four cases have to stay mutually exclusive and exhaustive.
 */
enum FacetMatch: string
{
    /** Same value — green. */
    case EXACT = 'exact';

    /** Near miss: a shared genre, a year within a few, an actor in common — yellow. */
    case CLOSE = 'close';

    /** Nothing in common — grey. */
    case NONE = 'none';

    /** One of the two films has no value to compare, so neither does the tile — grey, muted. */
    case UNKNOWN = 'unknown';
}
