<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\ListEntrySearchCriteria;
use App\Entity\Enum\ListEntrySortField;
use App\Entity\Enum\MediaType;
use App\Entity\MovieList;
use App\Entity\MovieListEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MovieList>
 *
 * "Watched" means the same thing in every query below: the list's owner has at least one
 * watch row for the work, whatever its source.
 */
class MovieListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovieList::class);
    }

    public function findOneByUserAndUrl(User $user, string $letterboxdUrl): ?MovieList
    {
        return $this->findOneBy(['user' => $user, 'letterboxdUrl' => $letterboxdUrl]);
    }

    /**
     * Scoped to its owner in the query itself: someone else's list is not found, rather than
     * found and then refused, so the response cannot confirm that it exists.
     */
    public function findOneOwnedBy(User $user, string $id): ?MovieList
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Every list of the profile with its tallies, newest first.
     *
     * @return list<array{id: string, name: string, description: ?string, tags: string, letterboxd_url: string, created_on: ?string, work_count: int, watched_count: int}>
     */
    public function summaries(User $user): array
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT l.id, l.name, l.description, l.tags, l.letterboxd_url, l.created_on,
                COUNT(e.id) AS work_count,
                COUNT(seen.movie_id) AS watched_count
            FROM movie_list l
            LEFT JOIN movie_list_entry e ON e.list_id = l.id
            LEFT JOIN (SELECT DISTINCT w.movie_id FROM watch w WHERE w.user_id = :userId) seen ON seen.movie_id = e.movie_id
            WHERE l.user_id = :userId
            GROUP BY l.id
            ORDER BY l.created_on DESC NULLS LAST, LOWER(l.name) ASC',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();
    }

    /**
     * The first few posters of each list, in the list's own order — the cover of the index
     * card. Works without artwork are skipped rather than shown as gaps: a cover is a few
     * pictures, not a record of which ones TMDB is missing.
     *
     * @return array<string, list<string>> poster paths by list id
     */
    public function coverPosters(User $user, int $perList): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT list_id, poster_path FROM (
                SELECT e.list_id, m.poster_path,
                    ROW_NUMBER() OVER (PARTITION BY e.list_id ORDER BY e.position, e.id) AS slot
                FROM movie_list_entry e
                JOIN movie_list l ON l.id = e.list_id
                JOIN movie m ON m.id = e.movie_id
                WHERE l.user_id = :userId AND m.poster_path IS NOT NULL
            ) ranked
            WHERE slot <= :perList
            ORDER BY list_id, slot',
            ['userId' => (string) $user->getId(), 'perList' => $perList],
            ['perList' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $posters = [];
        foreach ($rows as $row) {
            $posters[(string) $row['list_id']][] = (string) $row['poster_path'];
        }

        return $posters;
    }

    /**
     * What one list amounts to for the profile reading it.
     *
     * The average is taken per work — each watched work's own mean, then the mean of those —
     * so a film rewatched four times does not pull the list's figure towards its note.
     *
     * @return array{work_count: int, movie_count: int, series_count: int, watched_count: int, remaining_runtime: ?int, remaining_without_runtime: int, average_rating: ?string}
     */
    public function tallies(MovieList $list, User $user): array
    {
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COUNT(e.id) AS work_count,
                COUNT(e.id) FILTER (WHERE m.media_type = :movie) AS movie_count,
                COUNT(e.id) FILTER (WHERE m.media_type = :series) AS series_count,
                COUNT(seen.movie_id) AS watched_count,
                SUM(m.runtime_minutes) FILTER (WHERE seen.movie_id IS NULL AND m.media_type = :movie) AS remaining_runtime,
                COUNT(e.id) FILTER (WHERE seen.movie_id IS NULL AND m.media_type = :movie AND m.runtime_minutes IS NULL) AS remaining_without_runtime,
                AVG(seen.average_rating) AS average_rating
            FROM movie_list_entry e
            JOIN movie m ON m.id = e.movie_id
            LEFT JOIN (
                SELECT w.movie_id, AVG(w.rating) AS average_rating
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) seen ON seen.movie_id = e.movie_id
            WHERE e.list_id = :listId',
            [
                'listId' => (string) $list->getId(),
                'userId' => (string) $user->getId(),
                'movie' => MediaType::MOVIE->value,
                'series' => MediaType::SERIES->value,
            ]
        )->fetchAssociative() ?: [];

        return [
            'work_count' => (int) ($row['work_count'] ?? 0),
            'movie_count' => (int) ($row['movie_count'] ?? 0),
            'series_count' => (int) ($row['series_count'] ?? 0),
            'watched_count' => (int) ($row['watched_count'] ?? 0),
            'remaining_runtime' => isset($row['remaining_runtime']) ? (int) $row['remaining_runtime'] : null,
            'remaining_without_runtime' => (int) ($row['remaining_without_runtime'] ?? 0),
            'average_rating' => isset($row['average_rating']) ? (string) $row['average_rating'] : null,
        ];
    }

    /**
     * The genres the list's works carry — and only those, so the filter never offers a
     * choice that empties the page.
     *
     * @return list<string>
     */
    public function genresOf(MovieList $list): array
    {
        return array_values(array_map('strval', $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DISTINCT g.name
            FROM movie_list_entry e
            JOIN movie_genre mg ON mg.movie_id = e.movie_id
            JOIN genre g ON g.id = mg.genre_id
            WHERE e.list_id = :listId
            ORDER BY g.name',
            ['listId' => (string) $list->getId()]
        )->fetchFirstColumn()));
    }

    /**
     * One page of a list's entries. Raw SQL for the ids, as in MovieRepository::search: the
     * rating sort reads a per-profile aggregate DQL cannot put in an ORDER BY.
     *
     * @return array{items: list<MovieListEntry>, total: int}
     */
    public function searchEntries(MovieList $list, User $user, ListEntrySearchCriteria $criteria): array
    {
        $params = ['listId' => (string) $list->getId(), 'userId' => (string) $user->getId()];
        $conditions = ['e.list_id = :listId'];

        if (null !== $criteria->query && '' !== $criteria->query) {
            $conditions[] = '(LOWER(m.title) LIKE :query OR LOWER(m.original_title) LIKE :query)';
            // A % or _ typed in the search box is a literal character, not a wildcard.
            $params['query'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($criteria->query)).'%';
        }

        if (null !== $criteria->watched) {
            $conditions[] = $criteria->watched ? 'agg.movie_id IS NOT NULL' : 'agg.movie_id IS NULL';
        }

        if (null !== $criteria->mediaType) {
            $conditions[] = 'm.media_type = :mediaType';
            $params['mediaType'] = $criteria->mediaType->value;
        }

        if (null !== $criteria->genre && '' !== $criteria->genre) {
            $conditions[] = 'EXISTS (SELECT 1 FROM movie_genre mg JOIN genre g ON g.id = mg.genre_id WHERE mg.movie_id = m.id AND g.name = :genre)';
            $params['genre'] = $criteria->genre;
        }

        $from = 'FROM movie_list_entry e
            JOIN movie m ON m.id = e.movie_id
            LEFT JOIN (
                SELECT w.movie_id, AVG(w.rating) AS average_rating
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) agg ON agg.movie_id = m.id
            WHERE '.implode(' AND ', $conditions);

        $connection = $this->getEntityManager()->getConnection();
        $total = (int) $connection->executeQuery("SELECT COUNT(*) {$from}", $params)->fetchOne();

        $pageParams = $params + ['limit' => $criteria->perPage, 'offset' => $criteria->offset()];
        if (ListEntrySortField::RANDOM === $criteria->sort) {
            $pageParams['seed'] = $criteria->seed;
        }

        $ids = array_map('strval', $connection->executeQuery(
            "SELECT e.id {$from} ORDER BY {$this->orderBy($criteria)} LIMIT :limit OFFSET :offset",
            $pageParams,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        )->fetchFirstColumn());

        return ['items' => $this->entriesInOrder($ids), 'total' => $total];
    }

    /**
     * Never interpolates user input: the direction comes from a bool and the columns from a
     * closed enum. The list's own order breaks every tie, so two films of the same year stay
     * in the order their owner put them.
     */
    private function orderBy(ListEntrySearchCriteria $criteria): string
    {
        $direction = $criteria->descending ? 'DESC' : 'ASC';
        $tieBreak = 'e.position ASC, e.id ASC';

        return match ($criteria->sort) {
            ListEntrySortField::POSITION => "e.position {$direction}, e.id ASC",
            ListEntrySortField::TITLE => "LOWER(m.title) {$direction}, {$tieBreak}",
            ListEntrySortField::YEAR => "m.release_year {$direction} NULLS LAST, {$tieBreak}",
            ListEntrySortField::RATING => "agg.average_rating {$direction} NULLS LAST, {$tieBreak}",
            ListEntrySortField::RUNTIME => "m.runtime_minutes {$direction} NULLS LAST, {$tieBreak}",
            // Same seeded hash as the library: each seed its own stable permutation, so
            // paging through a shuffle neither repeats nor skips a film.
            ListEntrySortField::RANDOM => 'md5(:seed || e.id::text) ASC',
        };
    }

    /**
     * @param list<string> $ids
     *
     * @return list<MovieListEntry>
     */
    private function entriesInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<MovieListEntry> $entries */
        // The watches ride along in the same query, as in MovieRepository::findByIdsOrdered:
        // MovieMapper narrows them to the viewed profile, and without this every card on the
        // page would lazy-load its own.
        $entries = $this->getEntityManager()->createQueryBuilder()
            ->select('e', 'm', 'w')
            ->from(MovieListEntry::class, 'e')
            ->join('e.movie', 'm')
            ->leftJoin('m.watches', 'w')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($entries as $entry) {
            $byId[(string) $entry->getId()] = $entry;
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }
}
