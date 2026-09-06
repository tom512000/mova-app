<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FranchiseFilmDto
{
    /**
     * @param string|null $movieId the library's own id, when it holds this film — that is
     *                             what makes the row a link rather than a dead line
     * @param bool        $watched whether the viewed profile has watched it; a film can sit
     *                             in the library unwatched, which is the watchlist case
     */
    public function __construct(
        public int $tmdbId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public ?string $movieId,
        public bool $watched,
    ) {
    }
}
