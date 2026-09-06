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

    /**
     * TMDB's series catalogue is numbered independently of its film one, so $tvId is not
     * interchangeable with the id taken by getMovieDetails().
     *
     * Asks for aggregate_credits rather than credits: the series-level `credits` is a
     * token cast (7 people on Loki) while `aggregate_credits` spans every episode (92),
     * at the cost of a different per-person shape — see TmdbSeriesMapper.
     *
     * @return array<string, mixed> series details with aggregate_credits and external_ids appended
     */
    public function getTvDetails(int $tvId): array;

    /**
     * A TMDB collection - a franchise - with every film it lists in `parts`.
     *
     * One call per franchise rather than per film: `belongs_to_collection` already rides
     * along on every /movie response and names the franchise, but only says which films
     * are in it here.
     *
     * @return array<string, mixed> collection details, including a `parts` list
     */
    public function getCollection(int $collectionId): array;

    /**
     * One season's episode list. The only place a series' real running time can be read:
     * TMDB leaves `episode_run_time` empty on most modern series, so the total has to be
     * summed from the episodes themselves.
     *
     * @return array<string, mixed> season details, including an `episodes` list
     */
    public function getTvSeason(int $tvId, int $seasonNumber): array;
}
