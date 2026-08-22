<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

interface TmdbClientInterface
{
    /**
     * @return array<int, array{id: int, title: string, original_title: string, release_date: string, popularity: float}>
     */
    public function searchMovie(string $query, ?int $year): array;

    /**
     * @return array<string, mixed> movie details with credits and external_ids appended
     */
    public function getMovieDetails(int $tmdbId): array;
}
