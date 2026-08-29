<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every lookup here takes the owning User. Watches are per-account, so an unscoped query
 * would let one user's diary decide whether another user's import creates a row.
 *
 * @extends ServiceEntityRepository<Watch>
 */
class WatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Watch::class);
    }

    public function findOneByExternalRef(User $user, string $externalRef): ?Watch
    {
        return $this->findOneBy(['user' => $user, 'externalRef' => $externalRef]);
    }

    public function hasAnyWatch(User $user, Movie $movie): bool
    {
        return null !== $this->createQueryBuilder('w')
            ->select('w.id')
            ->where('w.movie = :movie')
            ->andWhere('w.user = :user')
            ->setParameter('movie', $movie)
            ->setParameter('user', $user)
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
    public function findOneWithoutExternalRefByMovie(User $user, Movie $movie): ?Watch
    {
        return $this->createQueryBuilder('w')
            ->where('w.movie = :movie')
            ->andWhere('w.user = :user')
            ->andWhere('w.externalRef IS NULL')
            ->setParameter('movie', $movie)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The most recent viewing of a film, whatever wrote it down.
     *
     * RatingsImporter compares its row's date against this one to tell three situations
     * apart: the same viewing being re-imported, a later one that has not been recorded yet,
     * and an older export being loaded after a newer one. Ordered by id as a tie-break so
     * that two viewings sharing a date resolve to the same row on every run rather than
     * whichever the planner returned first.
     *
     * The HIDDEN expression is there because Postgres sorts NULLs first in a DESC order, so
     * a viewing with no date at all — watched.csv makes those — would otherwise come back as
     * the most recent one and every later import would be compared against a row that says
     * nothing. Undated viewings sort last instead.
     */
    public function findLatestByMovie(User $user, Movie $movie): ?Watch
    {
        return $this->createQueryBuilder('w')
            ->addSelect('CASE WHEN w.watchedDate IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->where('w.movie = :movie')
            ->andWhere('w.user = :user')
            ->setParameter('movie', $movie)
            ->setParameter('user', $user)
            ->orderBy('undated', 'ASC')
            ->addOrderBy('w.watchedDate', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Used by ReviewsImporter to attach a review to the diary Watch that matches
     * the same film and watched date.
     */
    public function findOneByMovieAndWatchedDate(User $user, Movie $movie, \DateTimeImmutable $watchedDate): ?Watch
    {
        return $this->createQueryBuilder('w')
            ->where('w.movie = :movie')
            ->andWhere('w.user = :user')
            ->andWhere('w.watchedDate = :watchedDate')
            ->setParameter('movie', $movie)
            ->setParameter('user', $user)
            ->setParameter('watchedDate', $watchedDate)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
