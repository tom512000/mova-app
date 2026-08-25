<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LetterboxdSyncState;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LetterboxdSyncState>
 */
class LetterboxdSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LetterboxdSyncState::class);
    }

    public function findOneByUser(User $user): ?LetterboxdSyncState
    {
        return $this->findOneBy(['user' => $user]);
    }
}
