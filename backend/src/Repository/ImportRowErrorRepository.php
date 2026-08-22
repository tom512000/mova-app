<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRowError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRowError>
 */
class ImportRowErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRowError::class);
    }
}
