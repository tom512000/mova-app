<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CardPackOpening;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardPackOpening>
 */
class CardPackOpeningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardPackOpening::class);
    }

    /**
     * The last few openings, newest first — what lets a client that lost its connection
     * mid-animation replay the pack it already paid for.
     *
     * @return list<CardPackOpening>
     */
    public function recentForUser(User $user, int $limit): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.openedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
