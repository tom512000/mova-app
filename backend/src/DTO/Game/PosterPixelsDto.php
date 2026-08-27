<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * The answer's poster reduced to the handful of pixels the player has earned.
 *
 * The grid itself is what crosses the wire — not a URL, not a full-size image the browser
 * is asked to blur. At the opening rung the payload is a few dozen colours: there is
 * literally nothing in it to recover the poster from, which is the point. The browser
 * blows those pixels back up to poster size.
 */
final readonly class PosterPixelsDto
{
    /**
     * @param int          $step   how many rungs have been climbed, 1-based, for the caption
     * @param int          $steps  how many there are in total
     * @param list<string> $colors row-major '#rrggbb', exactly width * height of them
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $step,
        public int $steps,
        public array $colors,
    ) {
    }
}
