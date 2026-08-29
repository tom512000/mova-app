<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportBatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportBatch>
 */
class ImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatch::class);
    }

    /**
     * Scoped find: returns null for a batch that exists but belongs to someone else, so the
     * controller answers 404 rather than leaking another account's import history.
     */
    public function findOneOwnedBy(User $user, string $id): ?ImportBatch
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * @return ImportBatch[]
     */
    public function findRecentForUser(User $user, int $limit = 50): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC'], $limit);
    }
}
