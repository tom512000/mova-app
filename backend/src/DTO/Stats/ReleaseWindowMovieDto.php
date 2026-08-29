<?php

declare(strict_types=1);

namespace App\DTO\Stats;

/**
 * A film caught in its opening window, with the gap that earned it the place.
 */
final readonly class ReleaseWindowMovieDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public string $releaseDate,
        /** The first time it was watched — a rewatch cannot make a film "seen at release". */
        public string $firstWatchedDate,
        public int $daysAfterRelease,
    ) {
    }
}
