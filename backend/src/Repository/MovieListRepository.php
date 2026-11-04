<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MovieList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MovieList>
 */
class MovieListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovieList::class);
    }

    public function findOneByUserAndUrl(User $user, string $letterboxdUrl): ?MovieList
    {
        return $this->findOneBy(['user' => $user, 'letterboxdUrl' => $letterboxdUrl]);
    }
}
