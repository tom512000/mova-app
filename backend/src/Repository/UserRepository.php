<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Every account the scheduled RSS sync should poll. Users without a Letterboxd
     * username are skipped rather than filtered later, so the handler never has to
     * guess which account a sync belongs to.
     *
     * @return User[]
     */
    public function findWithRssSyncEnabled(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.rssSyncEnabled = true')
            ->andWhere('u.letterboxdUsername IS NOT NULL')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
