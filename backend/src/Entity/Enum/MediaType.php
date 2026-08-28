<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What kind of work a Movie row actually holds.
 *
 * Letterboxd exports films and the handful of series it accepts (mini-series, some
 * anthologies) in the same files, with the same row shape — one slug, one title, one
 * rating, one date. They stay in the same table for that reason, and this discriminator
 * is what tells the two apart where the difference matters.
 *
 * It matters in exactly three places, and nowhere else:
 *
 *   1. TMDB numbers its film and series catalogues independently, so movie/84958 and
 *      tv/84958 are unrelated works. The unique constraint on Movie is therefore over
 *      (mediaType, tmdbId), never tmdbId alone.
 *   2. The enrichment path branches here: /movie/{id} and /tv/{id} return different
 *      field names for the same facts (see TmdbSeriesMapper).
 *   3. Two stats deliberately exclude series — see OverviewStatsService (a 10-hour
 *      series would win "longest film" forever) and ReleaseWindowStatsService (being
 *      there "at release" is meaningless for a work aired over two months).
 */
enum MediaType: string
{
    case MOVIE = 'movie';
    case SERIES = 'series';
}
