<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Movie;
use App\Entity\User;
use App\Entity\WatchlistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function search(User $user, ?string $query, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('we')
            ->join('we.movie', 'm')
            ->where('we.user = :user')
            ->setParameter('user', $user);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(m.title) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        $total = (clone $qb)->select('COUNT(we.id)')->getQuery()->getSingleScalarResult();

        $entries = $qb->select('we', 'm')
            ->orderBy('we.addedDate', 'DESC')
            ->addOrderBy('m.title', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(static fn (WatchlistEntry $entry) => $entry->getMovie(), $entries),
            'total' => (int) $total,
        ];
    }
}
