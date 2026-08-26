<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\MovieFacetsDto;
use App\DTO\MovieSearchCriteria;
use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\MovieSortField;
use App\Entity\Movie;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Movie>
 */
class MovieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Movie::class);
    }

    public function findOneByLetterboxdSlug(string $slug): ?Movie
    {
        return $this->findOneBy(['letterboxdSlug' => $slug]);
    }

    public function findOneByTmdbId(int $tmdbId): ?Movie
    {
        return $this->findOneBy(['tmdbId' => $tmdbId]);
    }

    /**
     * Sorting on a per-profile average forces the aggregate into the query itself, which DQL
     * cannot express in an ORDER BY — hence raw SQL for the page's ids, then one hydration
     * pass for the entities.
     *
     * @return array{items: list<Movie>, total: int}
     */
    public function search(User $user, MovieSearchCriteria $criteria): array
    {
        $params = ['userId' => $user->getId()];
        $conditions = [];

        if (null !== $criteria->query && '' !== $criteria->query) {
            $conditions[] = '(LOWER(m.title) LIKE :query OR LOWER(m.original_title) LIKE :query)';
            // A % or _ typed in the search box is a literal character, not a wildcard.
            $params['query'] = '%'.str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\%', '\_'],
                mb_strtolower($criteria->query)
            ).'%';
        }

        if (null !== $criteria->genre && '' !== $criteria->genre) {
            $conditions[] = 'EXISTS (SELECT 1 FROM movie_genre mg JOIN genre g ON g.id = mg.genre_id WHERE mg.movie_id = m.id AND g.name = :genre)';
            $params['genre'] = $criteria->genre;
        }

        if (null !== $criteria->year) {
            $conditions[] = 'm.release_year = :year';
            $params['year'] = $criteria->year;
        }

        if ($criteria->unratedOnly) {
            $conditions[] = 'agg.average_rating IS NULL';
        } elseif (null !== $criteria->rating) {
            // The card shows an average, but the filter answers "did I ever give it that
            // note?" — a rewatch scored differently belongs under both of its notes.
            $conditions[] = 'EXISTS (SELECT 1 FROM watch wr WHERE wr.movie_id = m.id AND wr.user_id = :userId AND wr.rating = :rating)';
            $params['rating'] = number_format($criteria->rating, 1, '.', '');
        }

        if (null !== $criteria->personId) {
            $credit = 'EXISTS (SELECT 1 FROM credit c WHERE c.movie_id = m.id AND c.person_id = :personId';
            $params['personId'] = $criteria->personId;
            if (null !== $criteria->personRole) {
                $credit .= ' AND c.role = :personRole';
                $params['personRole'] = $criteria->personRole->value;
            }
            $conditions[] = $credit.')';
        }

        // The join to the aggregate is also what restricts the shared catalogue to the films
        // this profile has actually watched.
        $from = 'FROM movie m
            JOIN (
                SELECT w.movie_id,
                    AVG(w.rating) AS average_rating,
                    MAX(w.watched_date) AS last_watched_date,
                    MAX(w.created_at) AS added_at
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) agg ON agg.movie_id = m.id';
        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);

        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery("SELECT COUNT(*) {$from}{$where}", $params)->fetchOne();

        $pageParams = $params;
        if (MovieSortField::RANDOM === $criteria->sort) {
            $pageParams['seed'] = $criteria->seed ?? '';
        }
        $pageParams['limit'] = $criteria->perPage;
        $pageParams['offset'] = $criteria->offset();

        $ids = $connection->executeQuery(
            "SELECT m.id {$from}{$where} ORDER BY {$this->orderBy($criteria)} LIMIT :limit OFFSET :offset",
            $pageParams,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return [
            'items' => $this->findByIdsOrdered(array_map('intval', $ids)),
            'total' => $total,
        ];
    }

    public function facetsFor(User $user): MovieFacetsDto
    {
        $connection = $this->getEntityManager()->getConnection();
        $params = ['userId' => $user->getId()];

        $genres = $connection->executeQuery(
            'SELECT DISTINCT g.name
            FROM genre g
            JOIN movie_genre mg ON mg.genre_id = g.id
            JOIN watch w ON w.movie_id = mg.movie_id AND w.user_id = :userId
            ORDER BY g.name',
            $params
        )->fetchFirstColumn();

        $years = $connection->executeQuery(
            'SELECT DISTINCT m.release_year
            FROM movie m
            JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
            WHERE m.release_year IS NOT NULL
            ORDER BY m.release_year DESC',
            $params
        )->fetchFirstColumn();

        $ratings = $connection->executeQuery(
            'SELECT DISTINCT w.rating
            FROM watch w
            WHERE w.user_id = :userId AND w.rating IS NOT NULL
            ORDER BY w.rating DESC',
            $params
        )->fetchFirstColumn();

        // COUNT over a nullable column skips nulls, so a zero means the film carries no
        // note on any of its watches.
        $hasUnrated = (bool) $connection->executeQuery(
            'SELECT EXISTS (
                SELECT 1 FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
                HAVING COUNT(w.rating) = 0
            )',
            $params
        )->fetchOne();

        return new MovieFacetsDto(
            genres: array_values(array_map('strval', $genres)),
            years: array_values(array_map('intval', $years)),
            ratings: array_values(array_map('floatval', $ratings)),
            hasUnrated: $hasUnrated,
        );
    }

    /**
     * Never interpolates user input: the direction comes from a bool and the columns from a
     * closed enum. Films with nothing to sort on stay at the bottom whichever way the sort
     * points, which is why NULLS LAST is spelled out rather than left to the SQL default.
     */
    private function orderBy(MovieSearchCriteria $criteria): string
    {
        $direction = $criteria->descending ? 'DESC' : 'ASC';
        $tieBreak = 'LOWER(m.title) ASC, m.id ASC';

        return match ($criteria->sort) {
            MovieSortField::TITLE => "LOWER(m.title) {$direction}, m.id ASC",
            MovieSortField::RATING => "agg.average_rating {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::YEAR => "m.release_year {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::WATCHED => "agg.last_watched_date {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::ADDED => "agg.added_at {$direction}, {$tieBreak}",
            MovieSortField::RUNTIME => "m.runtime_minutes {$direction} NULLS LAST, {$tieBreak}",
            // Hashing the seed together with the id gives each seed its own stable
            // permutation, so paging through a shuffle neither repeats nor skips a film.
            MovieSortField::RANDOM => 'md5(:seed || m.id::text) ASC',
        };
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Movie>
     */
    private function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // addSelect('w') pulls the whole page's watches in this one query — MovieMapper
        // narrows them to the viewed profile afterwards, and without it every card would
        // lazy-load its own collection.
        $movies = $this->createQueryBuilder('m')
            ->addSelect('w')
            ->leftJoin('m.watches', 'w')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($movies as $movie) {
            $byId[$movie->getId()] = $movie;
        }

        // SQL gave the order; the hydration above did not preserve it.
        return array_values(array_filter(array_map(
            static fn (int $id) => $byId[$id] ?? null,
            $ids
        )));
    }

    /**
     * Movies TmdbResolver already matched and closed as ENRICHED. They are never revisited
     * by findNeedingEnrichment(), so a wrong-but-confident match stays wrong forever unless
     * something re-checks it — see app:tmdb:audit-matches.
     *
     * @return Movie[]
     */
    public function findEnrichedForAudit(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.enrichmentStatus = :status')
            ->andWhere('m.tmdbId IS NOT NULL')
            ->setParameter('status', EnrichmentStatus::ENRICHED)
            ->orderBy('m.id', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Movie[]
     */
    public function findNeedingEnrichment(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.enrichmentStatus IN (:statuses)')
            ->setParameter('statuses', [EnrichmentStatus::PENDING, EnrichmentStatus::FAILED, EnrichmentStatus::AMBIGUOUS])
            ->orderBy('m.lastEnrichmentAttemptAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
