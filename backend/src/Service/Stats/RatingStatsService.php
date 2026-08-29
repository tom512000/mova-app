<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\RatingStatsDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class RatingStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getRatingStats(User $user): RatingStatsDto
    {
        $ratings = array_map(
            static fn (array $row) => (float) $row['rating'],
            $this->entityManager->getConnection()
                ->executeQuery('SELECT rating FROM watch WHERE rating IS NOT NULL AND user_id = :userId', ['userId' => (string) $user->getId()])
                ->fetchAllAssociative()
        );

        // PHP silently casts float array keys to int (0.5 => 0, 3.5 => 3, ...), which
        // collapsed every half-star bucket into the whole-star one below it. Using a
        // fixed-precision string key keeps all 10 buckets (0.5 to 5.0) distinct.
        $counts = [];
        for ($step = 1; $step <= 10; ++$step) {
            $counts[number_format($step / 2, 1)] = 0;
        }
        foreach ($ratings as $rating) {
            $key = number_format($rating, 1);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);

        $distribution = [];
        foreach ($counts as $rating => $count) {
            $distribution[] = ['rating' => (float) $rating, 'count' => $count];
        }

        return new RatingStatsDto(
            average: StatsMath::mean($ratings),
            median: StatsMath::median($ratings),
            standardDeviation: StatsMath::stddev($ratings),
            distribution: $distribution,
        );
    }
}
