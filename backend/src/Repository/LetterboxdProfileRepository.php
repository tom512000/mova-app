<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LetterboxdProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LetterboxdProfile>
 */
class LetterboxdProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LetterboxdProfile::class);
    }

    public function findOneByUser(User $user): ?LetterboxdProfile
    {
        return $this->findOneBy(['user' => $user]);
    }
}
