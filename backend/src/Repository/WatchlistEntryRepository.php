<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\WatchlistFacetsDto;
use App\DTO\WatchlistSearchCriteria;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\WatchlistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WatchlistEntry>
 */
class WatchlistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchlistEntry::class);
    }

    public function findOneByMovie(User $user, Movie $movie): ?WatchlistEntry
    {
        return $this->findOneBy(['user' => $user, 'movie' => $movie]);
    }

    /**
     * @return array{items: Movie[], total: int}
     */
    public function search(User $user, WatchlistSearchCriteria $criteria): array
    {
        $qb = $this->filtered($user, $criteria);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT we.id)')->getQuery()->getSingleScalarResult();

        // The sort column second, the title third: two films added the same day, or two with
        // no runtime at all, would otherwise come back in whatever order the planner felt
        // like and shuffle themselves between pages.
        $entries = $qb->select('we', 'm')
            ->orderBy($criteria->sort->orderBy(), $criteria->descending ? 'DESC' : 'ASC')
            ->addOrderBy('m.title', 'ASC')
            ->setFirstResult($criteria->offset())
            ->setMaxResults($criteria->perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(static fn (WatchlistEntry $entry) => $entry->getMovie(), $entries),
            'total' => $total,
        ];
    }

    /**
     * One entry at random among those the filters keep — the "choose for me" button.
     *
     * The draw happens in PHP over the matching ids rather than in SQL, because a watchlist is
     * a few hundred rows at most and ORDER BY random() would mean dropping to raw SQL to
     * rebuild every filter a second time. Each press is a fresh draw on purpose: this is a
     * button you press again when you do not like the answer.
     */
    public function pickOne(User $user, WatchlistSearchCriteria $criteria): ?Movie
    {
        $ids = $this->filtered($user, $criteria)
            ->select('DISTINCT m.id')
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return null;
        }

        return $this->getEntityManager()->find(Movie::class, $ids[random_int(0, \count($ids) - 1)]);
    }

    /**
     * What this watchlist can be narrowed by, read from the watchlist itself.
     */
    public function facets(User $user): WatchlistFacetsDto
    {
        $connection = $this->getEntityManager()->getConnection();
        $params = ['userId' => (string) $user->getId()];

        // Three raw queries that do not pass through filtered(), so the "already watched"
        // exclusion has to be repeated rather than inherited. Leaving it out kept a genre in
        // the dropdown that nothing matched any more — a filter you could pick and get an
        // empty list from.
        $unwatched = ' AND NOT EXISTS (SELECT 1 FROM watch w WHERE w.movie_id = we.movie_id AND w.user_id = :userId)';

        $genres = $connection->executeQuery(
            'SELECT DISTINCT g.name
            FROM watchlist_entry we
            JOIN movie_genre mg ON mg.movie_id = we.movie_id
            JOIN genre g ON g.id = mg.genre_id
            WHERE we.user_id = :userId'.$unwatched.'
            ORDER BY g.name',
            $params
        )->fetchFirstColumn();

        // Newest decade first: a watchlist is mostly recent, and the useful end is the top.
        $decades = $connection->executeQuery(
            'SELECT DISTINCT (m.release_year / 10) * 10 AS decade
            FROM watchlist_entry we
            JOIN movie m ON m.id = we.movie_id
            WHERE we.user_id = :userId AND m.release_year IS NOT NULL'.$unwatched.'
            ORDER BY decade DESC',
            $params
        )->fetchFirstColumn();

        $bounds = $connection->executeQuery(
            'SELECT MIN(m.runtime_minutes) AS shortest, MAX(m.runtime_minutes) AS longest
            FROM watchlist_entry we
            JOIN movie m ON m.id = we.movie_id
            WHERE we.user_id = :userId AND m.runtime_minutes IS NOT NULL'.$unwatched,
            $params
        )->fetchAssociative() ?: [];

        return new WatchlistFacetsDto(
            genres: array_values(array_map('strval', $genres)),
            decades: array_values(array_map('intval', $decades)),
            shortestRuntime: isset($bounds['shortest']) ? (int) $bounds['shortest'] : null,
            longestRuntime: isset($bounds['longest']) ? (int) $bounds['longest'] : null,
        );
    }

    /**
     * Every filter, in one place, so the listing, the count and the draw can never disagree
     * about what is on the table.
     */
    private function filtered(User $user, WatchlistSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('we')
            ->join('we.movie', 'm')
            ->where('we.user = :user')
            // A film that has been watched is not on a watchlist, whatever the stored row
            // says. Letterboxd takes a film off yours the moment you log it, so its export
            // stops naming it — and an import that only ever adds rows kept it for ever.
            // Twenty-two of two hundred and seven entries were films already seen.
            //
            // The prune in WatchlistImporter now removes them on the next import, but this
            // stays, and not as a belt for that brace: an RSS sync records a viewing without
            // any import running at all, and between two exports this is the only thing that
            // notices. It is also the honest reading of the question the page answers, which
            // is "what is left to watch" and never "what did I once add".
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\Watch w WHERE w.movie = m AND w.user = :user)')
            ->setParameter('user', $user);

        if (null !== $criteria->query && '' !== $criteria->query) {
            $qb->andWhere('LOWER(m.title) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($criteria->query).'%');
        }

        if (null !== $criteria->maxRuntime) {
            // IS NOT NULL is the point as much as the comparison: see WatchlistSearchCriteria.
            $qb->andWhere('m.runtimeMinutes IS NOT NULL AND m.runtimeMinutes <= :maxRuntime')
                ->setParameter('maxRuntime', $criteria->maxRuntime);
        }

        if (null !== $criteria->genre && '' !== $criteria->genre) {
            $qb->join('m.genres', 'g')
                ->andWhere('g.name = :genre')
                ->setParameter('genre', $criteria->genre);
        }

        if (null !== $criteria->decade) {
            $qb->andWhere('m.releaseYear BETWEEN :decadeStart AND :decadeEnd')
                ->setParameter('decadeStart', $criteria->decade)
                ->setParameter('decadeEnd', $criteria->decade + 9);
        }

        return $qb;
    }
}
