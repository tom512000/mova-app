<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Card>
 *
 * Hydration only, and only for the handful of reads that want one card or six. The album
 * grid, the facet counts and the draw all go through raw DBAL in Service/Card — several
 * thousand rows paged, grouped and weighted is not work for the unit of work.
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * One card, scoped to its owner.
     *
     * The user is part of the lookup rather than checked afterwards: a card id is a UUID
     * belonging to exactly one account's catalogue, and asking for "this id, if it is yours"
     * makes a mismatch a 404 instead of something a caller has to remember to compare.
     */
    public function findOneForUser(User $user, Uuid $id): ?Card
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The showcase, in slot order. Six at most, and gaps are real — a slot left empty is not
     * the same as a shorter showcase, so the service pads this out rather than compacting it.
     *
     * @return list<Card>
     */
    public function findShowcase(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.showcasePosition IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('c.showcasePosition', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owned cards among a set of ids, scoped to their owner — what the showcase write
     * validates against before it pins anything.
     *
     * @param list<Uuid> $ids
     *
     * @return list<Card>
     */
    public function findOwnedByIds(User $user, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.id IN (:ids)')
            ->andWhere('c.copies > 0')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
