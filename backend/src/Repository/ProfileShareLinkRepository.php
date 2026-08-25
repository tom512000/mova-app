<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileShareLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileShareLink>
 */
class ProfileShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileShareLink::class);
    }

    public function findOneByToken(string $token): ?ProfileShareLink
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneByOwner(User $owner): ?ProfileShareLink
    {
        return $this->findOneBy(['owner' => $owner]);
    }
}
