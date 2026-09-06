<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/**
 * The genre that took over the year.
 *
 * Measured as a share of the year's viewings rather than as a raw increase, and that choice
 * is the whole block. On a year that simply grew — 312 viewings to 419 here — every genre
 * rises in absolute terms, so the biggest riser is just the biggest genre and the block says
 * nothing. Shares control for the volume and answer the question actually being asked: not
 * "what did you watch most of", but "what did you turn towards".
 */
final readonly class GenreShiftDto
{
    public function __construct(
        public string $genreName,
        public int $watchCount,
        /** Percent of the year's viewings, 0 to 100. */
        public float $share,
        /** The same share a year earlier. Null when there is no year before to compare to. */
        public ?float $previousShare,
    ) {
    }
}
