<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Movie;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @return array{items: Movie[], total: int}
     */
    public function search(User $user, ?string $query, ?string $genre, ?int $year, int $page, int $perPage): array
    {
        // "Films" means films *this profile* has watched — the Movie table itself is the
        // shared TMDB catalogue and holds every film any account ever imported.
        $qb = $this->createQueryBuilder('m')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Watch w WHERE w.movie = m AND w.user = :user)')
            ->setParameter('user', $user);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(m.title) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        if (null !== $genre && '' !== $genre) {
            $qb->join('m.genres', 'g')
                ->andWhere('g.name = :genre')
                ->setParameter('genre', $genre);
        }

        if (null !== $year) {
            $qb->andWhere('m.releaseYear = :year')
                ->setParameter('year', $year);
        }

        $total = (clone $qb)->select('COUNT(DISTINCT m.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->select('m')
            ->distinct()
            ->orderBy('m.title', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => (int) $total];
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
