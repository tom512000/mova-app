<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CardGameReward;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardGameReward>
 *
 * No finders: nothing reads these rows back. They exist so the unique constraint on the
 * session can refuse a second payout, which is a write-side guarantee, not a query.
 */
class CardGameRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardGameReward::class);
    }
}
