<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\RatingStatsDto;
use Doctrine\ORM\EntityManagerInterface;

final class RatingStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getRatingStats(): RatingStatsDto
    {
        $ratings = array_map(
            static fn (array $row) => (float) $row['rating'],
            $this->entityManager->getConnection()
                ->executeQuery('SELECT rating FROM watch WHERE rating IS NOT NULL')
                ->fetchAllAssociative()
        );

        $counts = [];
        for ($step = 1; $step <= 10; ++$step) {
            $counts[$step / 2] = 0;
        }
        foreach ($ratings as $rating) {
            $counts[$rating] = ($counts[$rating] ?? 0) + 1;
        }
        ksort($counts);

        $distribution = [];
        foreach ($counts as $rating => $count) {
            $distribution[] = ['rating' => $rating, 'count' => $count];
        }

        return new RatingStatsDto(
            average: StatsMath::mean($ratings),
            median: StatsMath::median($ratings),
            standardDeviation: StatsMath::stddev($ratings),
            distribution: $distribution,
        );
    }
}
