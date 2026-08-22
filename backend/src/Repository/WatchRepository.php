<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Movie;
use App\Entity\Watch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Watch>
 */
class WatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Watch::class);
    }

    public function findOneByExternalRef(string $externalRef): ?Watch
    {
        return $this->findOneBy(['externalRef' => $externalRef]);
    }

    public function hasAnyWatch(Movie $movie): bool
    {
        return null !== $this->createQueryBuilder('w')
            ->select('w.id')
            ->where('w.movie = :movie')
            ->setParameter('movie', $movie)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Used by RatingsImporter to find (or decide whether to create) the single placeholder
     * Watch representing "rated/marked watched outside of a diary entry" for a given movie,
     * so re-importing ratings.csv never duplicates it. Identified by the absence of an
     * externalRef (only diary-sourced Watches get one) — not by a null watchedDate, since
     * RatingsImporter now backfills that date from ratings.csv's own "Date" column and the
     * placeholder must still be found (not re-created) on a later re-import.
     */
    public function findOneWithoutExternalRefByMovie(Movie $movie): ?Watch
    {
        return $this->createQueryBuilder('w')
            ->where('w.movie = :movie')
            ->andWhere('w.externalRef IS NULL')
            ->setParameter('movie', $movie)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Used by ReviewsImporter to attach a review to the diary Watch that matches
     * the same film and watched date.
     */
    public function findOneByMovieAndWatchedDate(Movie $movie, \DateTimeImmutable $watchedDate): ?Watch
    {
        return $this->createQueryBuilder('w')
            ->where('w.movie = :movie')
            ->andWhere('w.watchedDate = :watchedDate')
            ->setParameter('movie', $movie)
            ->setParameter('watchedDate', $watchedDate)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
