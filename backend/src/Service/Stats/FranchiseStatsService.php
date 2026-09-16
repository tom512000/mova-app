<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\FranchiseStatDto;
use App\Entity\Enum\MediaType;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sagas the profile has started and not finished.
 *
 * Ordered by what is left rather than by what has been seen: a saga missing one film is a
 * thing you might do something about tonight, a saga missing seven is a project. Ties go to
 * the saga more of which has been watched, so "six of seven" outranks "one of two".
 *
 * "Missing" means not watched, which is not the same as not owned — a film sitting in the
 * watchlist is still missing from the tally, and that is the honest reading: the block
 * answers "what have I not seen", not "what do I not have".
 *
 * Only films carry a saga. TMDB has no collection concept for series, so no series ever
 * appears here, however many seasons it runs.
 *
 * Two things this used to get wrong, both of which made the block say something false:
 *
 * 1. It counted what had been watched through `movie.franchise_id` while listing what was
 *    missing through `franchise_film.tmdb_id`. Those are two different questions and they
 *    disagreed on six sagas here, because the backfill that stamps `franchise_id` does not
 *    reach every film — Bad Boys 2 is in the library, watched, and carries no saga, so the
 *    block claimed one film was missing and could not name it. Everything below now hangs
 *    off the TMDB id alone, which is also how a film's own saga panel reads it: a row of
 *    `franchise_film` is a fact about the saga, and whether the library happens to have
 *    stamped its foreign key is not part of that fact.
 *
 * 2. It counted films that do not exist yet. TMDB lists announced sequels, and thirty-seven
 *    of the seventy-one "unfinished" sagas here were finished — waiting on a film nobody
 *    could have watched. An unreleased film is excluded from the tally entirely rather than
 *    shown as missing, because "3 / 4" for a saga you have seen all of is not a to-do, it
 *    is a wrong number. $includeUpcoming brings them back for whoever wants to see what is
 *    coming.
 */
final class FranchiseStatsService
{
    /** Titles named per saga. Beyond a handful the card stops being readable. */
    private const MISSING_SHOWN = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param bool $includeUpcoming counts announced films that have not come out, so a saga
     *                              waiting on next year's sequel reads as unfinished
     *
     * @return FranchiseStatDto[]
     */
    public function getIncompleteFranchises(User $user, int $limit = 12, bool $includeUpcoming = false): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'WITH film AS (
                SELECT ff.franchise_id AS franchise_id,
                    ff.title AS title,
                    ff.release_date AS release_date,
                    -- Out, as of today. A row with no date at all is not out: TMDB leaves
                    -- the date off precisely when a film is announced and unscheduled.
                    (ff.release_date IS NOT NULL AND ff.release_date <= CURRENT_DATE) AS released,
                    -- The whole tally hangs off this lookup, never off movie.franchise_id.
                    -- media_type is part of it because TMDB numbers films and series in two
                    -- independent sequences, so an id alone can match a series that has
                    -- nothing to do with the saga.
                    EXISTS (
                        SELECT 1
                        FROM movie m
                        JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
                        WHERE m.tmdb_id = ff.tmdb_id AND m.media_type = :mediaType
                    ) AS watched
                FROM franchise_film ff
            ),
            counted AS (
                SELECT franchise_id, title, release_date, released, watched,
                    -- A film already watched always counts, whatever TMDB says its date is:
                    -- a wrong future date on a film somebody has seen must not quietly drop
                    -- it out of the total and make a finished saga look unfinished.
                    (watched OR released OR :includeUpcoming) AS counts
                FROM film
            )
            SELECT f.id AS franchise_id,
                f.name AS name,
                COUNT(*) FILTER (WHERE counted.watched) AS watched_count,
                COUNT(*) FILTER (WHERE counted.counts) AS total_count,
                COUNT(*) FILTER (WHERE NOT counted.watched AND NOT counted.released) AS upcoming_count,
                -- json rather than an array literal: a title with a comma in it cannot be
                -- parsed back out of "{a,b}" with any confidence.
                json_agg(counted.title ORDER BY counted.release_date ASC NULLS LAST, counted.title ASC)
                    FILTER (WHERE NOT counted.watched AND counted.counts) AS missing,
                json_agg(counted.title ORDER BY counted.release_date ASC NULLS LAST, counted.title ASC)
                    FILTER (WHERE NOT counted.watched AND NOT counted.released) AS upcoming
            FROM counted
            JOIN franchise f ON f.id = counted.franchise_id
            GROUP BY f.id, f.name
            -- Started, and not finished. Both halves matter: without the first the block
            -- would list every saga TMDB knows, most of which nobody has opened.
            HAVING COUNT(*) FILTER (WHERE counted.watched) > 0
                AND COUNT(*) FILTER (WHERE counted.watched) < COUNT(*) FILTER (WHERE counted.counts)
            ORDER BY COUNT(*) FILTER (WHERE counted.counts) - COUNT(*) FILTER (WHERE counted.watched) ASC,
                COUNT(*) FILTER (WHERE counted.watched) DESC,
                f.name ASC
            LIMIT :limit',
            [
                'userId' => (string) $user->getId(),
                'mediaType' => MediaType::MOVIE->value,
                'includeUpcoming' => $includeUpcoming,
                'limit' => $limit,
            ],
            [
                'includeUpcoming' => ParameterType::BOOLEAN,
                'limit' => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new FranchiseStatDto(
                franchiseId: (string) $row['franchise_id'],
                name: (string) $row['name'],
                watchedCount: (int) $row['watched_count'],
                totalCount: (int) $row['total_count'],
                upcomingCount: (int) $row['upcoming_count'],
                missing: \array_slice(self::titles($row['missing']), 0, self::MISSING_SHOWN),
                upcoming: \array_slice(self::titles($row['upcoming']), 0, self::MISSING_SHOWN),
            ),
            $rows
        );
    }

    /**
     * A FILTER that keeps nothing yields SQL NULL rather than an empty array.
     *
     * @return list<string>
     */
    private static function titles(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
