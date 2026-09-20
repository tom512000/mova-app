<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CardSetReward;
use App\Entity\Enum\CardSetFamily;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardSetReward>
 */
class CardSetRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardSetReward::class);
    }

    /**
     * The set keys already paid for, by family — what marks a completed set as spent when
     * the list is drawn, so a finished set the bonus was taken on does not keep shouting.
     *
     * This is a display concern only. The claim itself is not guarded by reading this: the
     * unique constraint is what makes a double claim impossible, because a check here and a
     * write afterwards is two statements a second request can slip between.
     *
     * @return array<string, list<string>> family value => set keys
     */
    public function claimedKeys(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.family AS family', 'r.setKey AS setKey')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        $claimed = [];
        foreach ($rows as $row) {
            $family = $row['family'] instanceof CardSetFamily ? $row['family']->value : (string) $row['family'];
            $claimed[$family][] = (string) $row['setKey'];
        }

        return $claimed;
    }
}
