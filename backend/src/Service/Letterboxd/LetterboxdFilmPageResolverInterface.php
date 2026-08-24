<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

interface LetterboxdFilmPageResolverInterface
{
    /**
     * `tmdbId` is only ever a TMDB *movie* id. When the Letterboxd entry actually points
     * at a TMDB series, `tmdbId` stays null and the id lands in `tmdbTvId` instead — the
     * two must never be conflated, since /movie/{id} and /tv/{id} are separate id spaces.
     *
     * @return array{tmdbId: int|null, tmdbTvId: int|null, imdbId: string|null}
     */
    public function resolve(string $slug): array;
}
