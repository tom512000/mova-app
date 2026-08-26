<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Studio>
 */
class StudioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Studio::class);
    }

    public function findOneByTmdbId(int $tmdbId): ?Studio
    {
        return $this->findOneBy(['tmdbId' => $tmdbId]);
    }
}
