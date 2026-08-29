<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\TimelineBucketDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class TimelineStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return TimelineBucketDto[]
     */
    public function getTimeline(User $user, string $granularity = 'year'): array
    {
        $format = 'month' === $granularity ? 'YYYY-MM' : 'YYYY';

        $rows = $this->entityManager->getConnection()->executeQuery(
            "SELECT
                to_char(w.watched_date, :format) AS period,
                COUNT(w.id) AS watch_count,
                COALESCE(SUM(m.runtime_minutes), 0) AS watch_time_minutes,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.watched_date IS NOT NULL
              AND w.user_id = :userId
            GROUP BY period
            ORDER BY period ASC",
            ['format' => $format, 'userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new TimelineBucketDto(
                period: $row['period'],
                watchCount: (int) $row['watch_count'],
                watchTimeMinutes: (int) $row['watch_time_minutes'],
                averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            ),
            $rows
        );
    }
}
