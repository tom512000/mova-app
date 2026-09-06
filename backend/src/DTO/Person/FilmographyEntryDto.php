<?php

declare(strict_types=1);

namespace App\DTO\Person;

/**
 * One film TMDB credits this person with that the profile has not watched.
 *
 * Carries a TMDB id and no library id on purpose: by definition nothing here is a work the
 * library holds and has seen, so there is nothing to link to inside the app.
 */
final readonly class FilmographyEntryDto
{
    public function __construct(
        public int $tmdbId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
    ) {
    }
}
