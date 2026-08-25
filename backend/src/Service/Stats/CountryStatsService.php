<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\CountryStatDto;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class CountryStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A co-production counts once for each country involved, so these totals deliberately
     * add up to more than the library size.
     *
     * @return CountryStatDto[]
     */
    public function getCountryStats(User $user, int $limit = 12): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                c.name AS country_name,
                c.iso_code,
                COUNT(DISTINCT m.id) AS movie_count,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            JOIN movie_country mc ON mc.movie_id = m.id
            JOIN country c ON c.id = mc.country_id
            WHERE w.user_id = :userId
            GROUP BY c.id, c.name, c.iso_code
            ORDER BY movie_count DESC
            LIMIT :limit',
            ['userId' => $user->getId(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new CountryStatDto(
                countryName: $row['country_name'],
                isoCode: $row['iso_code'],
                movieCount: (int) $row['movie_count'],
                averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            ),
            $rows
        );
    }
}
