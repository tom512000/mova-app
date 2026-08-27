<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\ReleaseWindowMovieDto;
use App\DTO\Stats\ReleaseWindowStatsDto;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Films seen while they were still in cinemas — "j'y étais à la sortie".
 *
 * Measured from TMDB's release_date, which is the film's primary release and not always the
 * French one; on a film that opened weeks apart either side of the Atlantic the gap here is
 * the distance from *that* date, not from the day it reached a French screen.
 */
final class ReleaseWindowStatsService
{
    /** The window. A month is the span over which a film is still "the new one". */
    public const WITHIN_DAYS = 31;

    /** The sharper cut inside it, worth calling out on its own. */
    private const FIRST_WEEK = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getReleaseWindowStats(User $user): ReleaseWindowStatsDto
    {
        $connection = $this->entityManager->getConnection();
        $params = ['userId' => $user->getId()];

        // The first viewing, not any viewing: rewatching a film ten years on says nothing
        // about having been there when it came out.
        $firstWatch = 'SELECT m.id, m.title, m.release_year, m.release_date, MIN(w.watched_date) AS first_watched
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.user_id = :userId
                AND m.release_date IS NOT NULL
                AND w.watched_date IS NOT NULL
            GROUP BY m.id, m.title, m.release_year, m.release_date';

        $rows = $connection->executeQuery(
            "WITH firsts AS ({$firstWatch})
            SELECT id, title, release_year, release_date, first_watched,
                (first_watched - release_date) AS days_after
            FROM firsts
            WHERE first_watched - release_date BETWEEN 0 AND :days
            ORDER BY days_after ASC, title ASC",
            $params + ['days' => self::WITHIN_DAYS],
            ['days' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $comparable = (int) $connection->executeQuery(
            "SELECT COUNT(*) FROM ({$firstWatch}) firsts",
            $params
        )->fetchOne();

        $movies = array_map(
            static fn (array $row) => new ReleaseWindowMovieDto(
                movieId: (int) $row['id'],
                title: $row['title'],
                releaseYear: null !== $row['release_year'] ? (int) $row['release_year'] : null,
                releaseDate: $row['release_date'],
                firstWatchedDate: $row['first_watched'],
                daysAfterRelease: (int) $row['days_after'],
            ),
            $rows
        );

        return new ReleaseWindowStatsDto(
            withinDays: self::WITHIN_DAYS,
            count: \count($movies),
            firstWeek: \count(array_filter(
                $movies,
                static fn (ReleaseWindowMovieDto $movie) => $movie->daysAfterRelease <= self::FIRST_WEEK
            )),
            comparable: $comparable,
            movies: $movies,
        );
    }
}
