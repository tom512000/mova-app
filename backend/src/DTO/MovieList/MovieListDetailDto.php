<?php

declare(strict_types=1);

namespace App\DTO\MovieList;

/**
 * One list's header: what it is, and what it amounts to for the profile reading it.
 */
final readonly class MovieListDetailDto
{
    /**
     * @param list<string> $tags
     * @param list<string> $genres the genres its works carry, for the filter bar
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $tags,
        public string $letterboxdUrl,
        public ?string $createdOn,
        public string $importedAt,
        public int $workCount,
        public int $movieCount,
        public int $seriesCount,
        public int $watchedCount,
        /** Films only, unwatched only: what the list still asks of an evening. */
        public ?int $remainingRuntimeMinutes,
        /** Unwatched works whose runtime TMDB does not know, so the figure above reads as a floor. */
        public int $remainingWithoutRuntime,
        /** Per work, not per viewing: a rewatched film does not weigh twice on its list. */
        public ?float $averageRating,
        public array $genres,
    ) {
    }
}
