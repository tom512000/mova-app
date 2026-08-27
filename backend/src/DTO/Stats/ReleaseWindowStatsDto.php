<?php

declare(strict_types=1);

namespace App\DTO\Stats;

/**
 * The films seen while they were still new.
 */
final readonly class ReleaseWindowStatsDto
{
    /**
     * @param int                        $withinDays  the window, in days after release
     * @param int                        $count       films whose first viewing fell inside it
     * @param int                        $firstWeek   how many of those landed inside a week
     * @param int                        $comparable  films this could be asked of at all — the
     *                                                honest denominator, since a film with no
     *                                                release date in TMDB can never qualify
     * @param list<ReleaseWindowMovieDto> $movies     closest to release first
     */
    public function __construct(
        public int $withinDays,
        public int $count,
        public int $firstWeek,
        public int $comparable,
        public array $movies,
    ) {
    }
}
