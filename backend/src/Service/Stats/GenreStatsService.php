<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\GenreStatDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class GenreStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return GenreStatDto[]
     */
    public function getGenreStats(User $user): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                g.name AS genre_name,
                COUNT(DISTINCT m.id) AS movie_count,
                COUNT(w.id) AS watch_count,
                AVG(w.rating) AS average_rating,
                COALESCE(SUM(m.runtime_minutes), 0) AS total_watch_time
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            JOIN movie_genre mg ON mg.movie_id = m.id
            JOIN genre g ON g.id = mg.genre_id
            WHERE w.user_id = :userId
            GROUP BY g.id, g.name
            ORDER BY watch_count DESC',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new GenreStatDto(
                genreName: $row['genre_name'],
                movieCount: (int) $row['movie_count'],
                watchCount: (int) $row['watch_count'],
                averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
                totalWatchTimeMinutes: (int) $row['total_watch_time'],
            ),
            $rows
        );
    }
}
