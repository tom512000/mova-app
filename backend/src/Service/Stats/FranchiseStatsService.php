<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\FranchiseStatDto;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sagas the profile has started and not finished.
 *
 * Ordered by what is left rather than by what has been seen: a saga missing one film is a
 * thing you might do something about tonight, a saga missing seven is a project. Ties go to
 * the saga more of which has been watched, so "six of seven" outranks "one of two".
 *
 * "Missing" means not watched, which is not the same as not owned — a film sitting in the
 * watchlist is still missing from the tally, and that is the honest reading: the block
 * answers "what have I not seen", not "what do I not have".
 *
 * Only films carry a saga. TMDB has no collection concept for series, so no series ever
 * appears here, however many seasons it runs.
 */
final class FranchiseStatsService
{
    /** Titles named per saga. Beyond a handful the card stops being readable. */
    private const MISSING_SHOWN = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return FranchiseStatDto[]
     */
    public function getIncompleteFranchises(User $user, int $limit = 12): array
    {
        $connection = $this->entityManager->getConnection();
        $userId = (string) $user->getId();

        $rows = $connection->executeQuery(
            'SELECT
                f.id AS franchise_id,
                f.name AS name,
                COUNT(DISTINCT m.id) AS watched_count,
                ff.total_count AS total_count
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            JOIN franchise f ON f.id = m.franchise_id
            JOIN (
                SELECT franchise_id, COUNT(*) AS total_count
                FROM franchise_film
                GROUP BY franchise_id
            ) ff ON ff.franchise_id = f.id
            WHERE w.user_id = :userId
            GROUP BY f.id, f.name, ff.total_count
            HAVING COUNT(DISTINCT m.id) < ff.total_count
            ORDER BY ff.total_count - COUNT(DISTINCT m.id) ASC, COUNT(DISTINCT m.id) DESC, f.name ASC
            LIMIT :limit',
            ['userId' => $userId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $missing = $this->missingTitles($userId, array_column($rows, 'franchise_id'));

        return array_map(
            static fn (array $row) => new FranchiseStatDto(
                franchiseId: (string) $row['franchise_id'],
                name: (string) $row['name'],
                watchedCount: (int) $row['watched_count'],
                totalCount: (int) $row['total_count'],
                missing: \array_slice($missing[(string) $row['franchise_id']] ?? [], 0, self::MISSING_SHOWN),
            ),
            $rows
        );
    }

    /**
     * The unwatched titles of every saga on the page, in one query rather than one per saga.
     *
     * @param list<string> $franchiseIds
     *
     * @return array<string, list<string>>
     */
    private function missingTitles(string $userId, array $franchiseIds): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT ff.franchise_id, ff.title
            FROM franchise_film ff
            WHERE ff.franchise_id IN (:franchiseIds)
                AND NOT EXISTS (
                    SELECT 1 FROM movie m
                    JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
                    WHERE m.tmdb_id = ff.tmdb_id
                )
            ORDER BY ff.release_date ASC NULLS LAST, ff.title ASC',
            ['franchiseIds' => $franchiseIds, 'userId' => $userId],
            ['franchiseIds' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $byFranchise = [];
        foreach ($rows as $row) {
            $byFranchise[(string) $row['franchise_id']][] = (string) $row['title'];
        }

        return $byFranchise;
    }
}
