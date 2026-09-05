<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\StudioStatDto;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The most-watched production companies.
 *
 * A film counts once for each studio credited on it, so the totals add up to more than the
 * library — the same convention as the countries ring, and for the same reason: TMDB gives a
 * flat list of production companies with no lead and no role, so there is nothing to pick a
 * single "real" studio with. The join table carries no ordering either, so even "the first
 * one TMDB listed" is not recoverable here.
 *
 * The consequence has to be stated wherever this ranking is displayed, because it is
 * genuinely surprising: broadcasters and financing arms are credited on everything they put
 * money into, so on a French library TF1 Films Production and Canal+ outrank the studios that
 * actually shot the films. That is a true fact about how films get financed, but a reader who
 * has not been told will read the block as "your favourite studio" and be wrong.
 */
final class StudioStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return StudioStatDto[]
     */
    public function getStudioStats(User $user, int $limit = 25): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                s.id AS studio_id,
                s.name AS name,
                COUNT(DISTINCT m.id) AS movie_count,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            JOIN movie_studio ms ON ms.movie_id = m.id
            JOIN studio s ON s.id = ms.studio_id
            WHERE w.user_id = :userId
            GROUP BY s.id, s.name
            ORDER BY movie_count DESC, average_rating DESC NULLS LAST
            LIMIT :limit',
            ['limit' => $limit, 'userId' => (string) $user->getId()],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => new StudioStatDto(
                studioId: (string) $row['studio_id'],
                name: $row['name'],
                movieCount: (int) $row['movie_count'],
                averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            ),
            $rows
        );
    }
}
