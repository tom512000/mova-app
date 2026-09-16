<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\DiscoveryDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who walked into the library for the first time in a given year.
 *
 * "For the first time" is the whole of it: somebody with a single work watched in 2019 is
 * not a discovery of 2026 however many of theirs were watched since. The earliest watched
 * work decides the year, and the year filter is applied to that — never to the works
 * themselves, which is the difference between this and every other per-year block.
 *
 * Direction and performance only, the same two jobs the retrospective's person of the year
 * counts and for the same reason: a producer credit is not why anybody picked a film, and
 * counting it hands the year to a production executive nobody watched anything for.
 */
final class DiscoveryStatsService
{
    /** @var list<CreditRole> */
    private const ROLES = [CreditRole::DIRECTOR, CreditRole::ACTOR];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * @return list<DiscoveryDto>
     */
    public function getDiscoveries(User $user, int $year, int $limit = 9): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'WITH watched AS (
                -- One row per work, carrying the day it was really watched. A note revised
                -- years later is not the evening somebody was met, so deduced rows are out:
                -- left in, a re-rating could move a whole career into the wrong year.
                SELECT m.id AS movie_id,
                    MIN(w.watched_date) FILTER (WHERE w.source <> :deducedSource) AS watched_on,
                    AVG(w.rating) AS rating
                FROM watch w
                JOIN movie m ON m.id = w.movie_id
                WHERE w.user_id = :userId
                GROUP BY m.id
            ),
            credited AS (
                -- One row per person and per work, never per credit. Somebody who directs
                -- what they star in carries two credits on the same film, and TMDB doubles
                -- ensemble actors up under two character names; either would weigh that film
                -- twice in the average below.
                SELECT c.person_id AS person_id,
                    c.movie_id AS movie_id,
                    BOOL_OR(c.role = :director) AS directed
                FROM credit c
                WHERE c.role IN (:roles)
                GROUP BY c.person_id, c.movie_id
            ),
            met AS (
                SELECT cr.person_id AS person_id,
                    MIN(wd.watched_on) AS first_seen,
                    COUNT(*) AS work_count,
                    AVG(wd.rating) AS average_rating,
                    BOOL_OR(cr.directed) AS directed
                FROM credited cr
                JOIN watched wd ON wd.movie_id = cr.movie_id
                GROUP BY cr.person_id
            )
            SELECT p.id AS person_id,
                p.name AS name,
                p.profile_path AS profile_path,
                met.first_seen AS first_seen,
                met.work_count AS work_count,
                met.average_rating AS average_rating,
                met.directed AS directed
            FROM met
            JOIN person p ON p.id = met.person_id
            WHERE EXTRACT(YEAR FROM met.first_seen) = :year
            -- What makes it a discovery worth naming is how much followed it, not the
            -- meeting itself: everybody met this year met it exactly once.
            ORDER BY met.work_count DESC, met.average_rating DESC NULLS LAST, LOWER(p.name) ASC
            LIMIT :limit',
            [
                'userId' => (string) $user->getId(),
                'deducedSource' => WatchSource::CSV_RERATING->value,
                'director' => CreditRole::DIRECTOR->value,
                'roles' => array_map(static fn (CreditRole $role) => $role->value, self::ROLES),
                'year' => $year,
                'limit' => $limit,
            ],
            [
                'roles' => ArrayParameterType::STRING,
                'year' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return array_map(fn (array $row) => new DiscoveryDto(
            personId: (string) $row['person_id'],
            name: (string) $row['name'],
            profileUrl: null !== $row['profile_path'] ? "{$this->imageBaseUrl}/w185{$row['profile_path']}" : null,
            role: $row['directed'] ? CreditRole::DIRECTOR : CreditRole::ACTOR,
            workCount: (int) $row['work_count'],
            averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            firstSeenOn: (string) $row['first_seen'],
        ), $rows);
    }
}
