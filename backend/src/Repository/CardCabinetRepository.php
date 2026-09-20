<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CardCabinet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardCabinet>
 */
class CardCabinetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardCabinet::class);
    }

    public function findOneByUser(User $user): ?CardCabinet
    {
        return $this->findOneBy(['user' => $user]);
    }
}
