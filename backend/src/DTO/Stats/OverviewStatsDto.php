<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class OverviewStatsDto
{
    public function __construct(
        public int $totalMovies,
        public int $totalWatches,
        public int $totalRewatches,
        public int $totalWatchlist,
        public ?float $averageRating,
        public ?float $medianRating,
        public int $totalWatchTimeMinutes,
        public ?float $averageMovieRuntimeMinutes,
        public ?MovieRuntimeDto $longestMovie,
        public ?MovieRuntimeDto $shortestMovie,
    ) {
    }
}
