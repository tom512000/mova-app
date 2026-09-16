<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\PersonSearchCriteria;
use App\Entity\Enum\PersonSortField;
use App\Entity\Enum\WatchSource;
use App\Entity\Person;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    public function findOneByTmdbId(int $tmdbId): ?Person
    {
        return $this->findOneBy(['tmdbId' => $tmdbId]);
    }

    /**
     * The directory: everybody this profile's library credits, with what it knows of them.
     *
     * Raw SQL over raw rows rather than hydrated entities, for the same reason the museum
     * wall uses them — a card here shows six aggregates and not one column of the person
     * row, so hydrating a page of Person objects would add a lazily-loaded credits
     * collection nobody is going to read.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(User $user, PersonSearchCriteria $criteria): array
    {
        $params = [
            'userId' => (string) $user->getId(),
            'deducedSource' => WatchSource::CSV_RERATING->value,
        ];
        $conditions = [];

        if (null !== $criteria->query && '' !== $criteria->query) {
            $conditions[] = 'LOWER(p.name) LIKE :query';
            // A % or _ typed in the search box is a literal character, not a wildcard.
            $params['query'] = '%'.str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\%', '\_'],
                mb_strtolower($criteria->query)
            ).'%';
        }

        if (null !== $criteria->role) {
            $conditions[] = 'c.role = :role';
            $params['role'] = $criteria->role->value;
        }

        if (null !== $criteria->mediaType) {
            $conditions[] = 'm.media_type = :mediaType';
            $params['mediaType'] = $criteria->mediaType->value;
        }

        $cte = $this->creditedWorks($conditions);
        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery(
            $cte.' SELECT COUNT(*) FROM (SELECT work.person_id FROM work GROUP BY work.person_id) people',
            $params
        )->fetchOne();

        $pageParams = $params;
        if (PersonSortField::RANDOM === $criteria->sort) {
            $pageParams['seed'] = $criteria->seed ?? '';
        }
        $pageParams['limit'] = $criteria->perPage;
        $pageParams['offset'] = $criteria->offset();

        $rows = $connection->executeQuery(
            $cte."
            SELECT
                p.id AS id,
                p.name AS name,
                p.profile_path AS profile_path,
                COUNT(*) FILTER (WHERE work.watched) AS watched_count,
                -- Only what was never watched: a film sitting in the watchlist *and* already
                -- seen is not something left to see, and counting it would inflate the one
                -- figure on the card that is meant to be actionable.
                COUNT(*) FILTER (WHERE work.in_watchlist AND NOT work.watched) AS watchlist_count,
                COUNT(*) AS work_count,
                AVG(work.average_rating) AS average_rating,
                MAX(work.last_watched_date) AS last_watched_date,
                -- One row per work, so DISTINCT here collapses the repetition rather than
                -- the jobs: it dedupes whole combinations, and somebody who directs some of
                -- what they act in still arrives as both combinations. The mapper flattens
                -- what is left and puts it in credit-block order.
                STRING_AGG(DISTINCT work.roles, ',') AS roles
            FROM work
            JOIN person p ON p.id = work.person_id
            GROUP BY p.id, p.name, p.profile_path
            ORDER BY {$this->orderBy($criteria)}
            LIMIT :limit OFFSET :offset",
            $pageParams,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * One row per person and per work of theirs the library holds — watched, or waiting in
     * the watchlist.
     *
     * The library is what makes this a directory rather than a dump of TMDB: the movie table
     * is a shared catalogue, so without that condition every account would be browsing every
     * other account's cast lists.
     *
     * Collapsing the credits to one row per work is the other half of the point, and it is
     * about the average. TMDB credits the same actor twice on one film often enough — two
     * character names on an ensemble cast — and somebody who writes and directs their own
     * films carries two credits on every one of them. Left at credit level those films would
     * weigh double in the AVG(), and the figure would be quietly wrong for precisely the
     * people this page is most likely to be opened on.
     *
     * The values reduced by MIN/MAX/BOOL_OR are per-film and joined in, so every row of a
     * group already carries the same one: the aggregate is how a GROUP BY is made to name
     * it, not a reduction of anything.
     *
     * @param list<string> $conditions
     */
    private function creditedWorks(array $conditions): string
    {
        $where = ' WHERE (agg.movie_id IS NOT NULL OR wl.movie_id IS NOT NULL)'
            .([] === $conditions ? '' : ' AND '.implode(' AND ', $conditions));

        return "WITH work AS (
            SELECT
                c.person_id AS person_id,
                MIN(agg.average_rating) AS average_rating,
                MAX(agg.last_watched_date) AS last_watched_date,
                BOOL_OR(agg.movie_id IS NOT NULL) AS watched,
                BOOL_OR(wl.movie_id IS NOT NULL) AS in_watchlist,
                STRING_AGG(DISTINCT c.role, ',') AS roles
            FROM credit c
            JOIN person p ON p.id = c.person_id
            JOIN movie m ON m.id = c.movie_id
            LEFT JOIN (
                SELECT w.movie_id,
                    AVG(w.rating) AS average_rating,
                    MAX(w.watched_date) FILTER (WHERE w.source <> :deducedSource) AS last_watched_date
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) agg ON agg.movie_id = c.movie_id
            LEFT JOIN watchlist_entry wl ON wl.movie_id = c.movie_id AND wl.user_id = :userId{$where}
            GROUP BY c.person_id, c.movie_id
        )";
    }

    /**
     * Never interpolates user input: the direction comes from a bool and the columns from a
     * closed enum. People with nothing to rank on stay at the bottom whichever way the sort
     * points, which is why NULLS LAST is spelled out rather than left to the SQL default.
     */
    private function orderBy(PersonSearchCriteria $criteria): string
    {
        $direction = $criteria->descending ? 'DESC' : 'ASC';
        $tieBreak = 'LOWER(p.name) ASC, p.id ASC';

        return match ($criteria->sort) {
            PersonSortField::NAME => "LOWER(p.name) {$direction}, p.id ASC",
            PersonSortField::WORKS => "COUNT(*) FILTER (WHERE work.watched) {$direction}, {$tieBreak}",
            PersonSortField::RATING => "AVG(work.average_rating) {$direction} NULLS LAST, {$tieBreak}",
            PersonSortField::RECENT => "MAX(work.last_watched_date) {$direction} NULLS LAST, {$tieBreak}",
            // Hashing the seed together with the id gives each seed its own stable
            // permutation, so paging through a shuffle neither repeats nor skips a name.
            PersonSortField::RANDOM => 'md5(:seed || p.id::text) ASC',
        };
    }
}
