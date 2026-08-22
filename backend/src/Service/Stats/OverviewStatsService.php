<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\MovieRuntimeDto;
use App\DTO\Stats\OverviewStatsDto;
use Doctrine\ORM\EntityManagerInterface;

final class OverviewStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getOverview(): OverviewStatsDto
    {
        $conn = $this->entityManager->getConnection();

        $totals = $conn->executeQuery(
            'SELECT
                COUNT(DISTINCT w.movie_id) AS total_movies,
                COUNT(w.id) AS total_watches,
                COUNT(*) FILTER (WHERE w.is_rewatch) AS total_rewatches
            FROM watch w'
        )->fetchAssociative();

        $ratings = array_map(
            static fn (array $row) => (float) $row['rating'],
            $conn->executeQuery('SELECT rating FROM watch WHERE rating IS NOT NULL')->fetchAllAssociative()
        );

        $totalWatchlist = $conn->executeQuery('SELECT COUNT(*) FROM watchlist_entry')->fetchOne();

        $watchTime = $conn->executeQuery(
            'SELECT COALESCE(SUM(m.runtime_minutes), 0) AS total_minutes
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE m.runtime_minutes IS NOT NULL'
        )->fetchOne();

        $averageRuntime = $conn->executeQuery(
            'SELECT AVG(m.runtime_minutes) AS avg_runtime
            FROM movie m
            WHERE m.runtime_minutes IS NOT NULL
              AND EXISTS (SELECT 1 FROM watch w WHERE w.movie_id = m.id)'
        )->fetchOne();

        $longest = $this->findExtremeRuntimeMovie($conn, 'DESC');
        $shortest = $this->findExtremeRuntimeMovie($conn, 'ASC');

        return new OverviewStatsDto(
            totalMovies: (int) $totals['total_movies'],
            totalWatches: (int) $totals['total_watches'],
            totalRewatches: (int) $totals['total_rewatches'],
            totalWatchlist: (int) $totalWatchlist,
            averageRating: StatsMath::mean($ratings),
            medianRating: StatsMath::median($ratings),
            totalWatchTimeMinutes: (int) $watchTime,
            averageMovieRuntimeMinutes: null !== $averageRuntime ? (float) $averageRuntime : null,
            longestMovie: $longest,
            shortestMovie: $shortest,
        );
    }

    private function findExtremeRuntimeMovie(\Doctrine\DBAL\Connection $conn, string $direction): ?MovieRuntimeDto
    {
        $row = $conn->executeQuery(
            "SELECT m.id, m.title, m.runtime_minutes
            FROM movie m
            WHERE m.runtime_minutes IS NOT NULL
              AND EXISTS (SELECT 1 FROM watch w WHERE w.movie_id = m.id)
            ORDER BY m.runtime_minutes {$direction}
            LIMIT 1"
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return new MovieRuntimeDto((int) $row['id'], $row['title'], (int) $row['runtime_minutes']);
    }
}
