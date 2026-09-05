<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\DecadeStatDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How the library spreads across decades of release, and how each decade is rated.
 *
 * Empty decades between the first and the last are returned with a zero count rather than
 * skipped. A bar chart whose axis jumps from 1950 to 1980 reads as "nothing much between",
 * when what it actually means is "nothing at all" — and the gap is the interesting part.
 * Nothing is padded outside that range: the axis starts at the oldest film watched.
 *
 * The average is taken over viewings, not over films, which is the same convention as
 * GenreStatsService and TimelineStatsService — a film watched twice weighs twice. With one
 * deduced re-rating row in the whole table the difference is not observable today, but the
 * dashboard has to agree with itself.
 */
final class DecadeStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return DecadeStatDto[]
     */
    public function getDecadeStats(User $user): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                (m.release_year / 10) * 10 AS decade,
                COUNT(DISTINCT m.id) AS movie_count,
                COUNT(w.id) AS watch_count,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.user_id = :userId AND m.release_year IS NOT NULL
            GROUP BY 1
            ORDER BY 1',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $byDecade = [];
        foreach ($rows as $row) {
            $byDecade[(int) $row['decade']] = $row;
        }

        $first = (int) $rows[0]['decade'];
        $last = (int) $rows[\count($rows) - 1]['decade'];

        $stats = [];
        for ($decade = $first; $decade <= $last; $decade += 10) {
            $row = $byDecade[$decade] ?? null;

            $stats[] = new DecadeStatDto(
                decade: $decade,
                movieCount: null !== $row ? (int) $row['movie_count'] : 0,
                watchCount: null !== $row ? (int) $row['watch_count'] : 0,
                // Null rather than zero for a decade nobody rated: a chart that draws 0
                // for "no data" invents a terrible score out of an absence.
                averageRating: null !== $row && null !== $row['average_rating']
                    ? round((float) $row['average_rating'], 2)
                    : null,
            );
        }

        return $stats;
    }
}
