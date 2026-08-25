<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\PersonStatDto;
use App\Entity\Enum\CreditRole;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Most-watched people" rankings. Directors, writers and actors are the same aggregation
 * over a different Credit role, so they share one query — three near-identical services was
 * already one copy too many when writers were added.
 */
final class PersonStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return PersonStatDto[]
     */
    public function getStats(User $user, CreditRole $role, int $limit = 25): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                p.id AS person_id,
                p.name AS name,
                COUNT(DISTINCT m.id) AS movie_count,
                AVG(w.rating) AS average_rating,
                MAX(w.rating) AS best_rating,
                MIN(w.rating) AS worst_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            JOIN credit c ON c.movie_id = m.id AND c.role = :role
            JOIN person p ON p.id = c.person_id
            WHERE w.user_id = :userId
            GROUP BY p.id, p.name
            ORDER BY movie_count DESC, average_rating DESC NULLS LAST
            LIMIT :limit',
            ['role' => $role->value, 'limit' => $limit, 'userId' => $user->getId()],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new PersonStatDto(
                personId: (int) $row['person_id'],
                name: $row['name'],
                movieCount: (int) $row['movie_count'],
                averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
                bestRating: null !== $row['best_rating'] ? (float) $row['best_rating'] : null,
                worstRating: null !== $row['worst_rating'] ? (float) $row['worst_rating'] : null,
            ),
            $rows
        );
    }
}
