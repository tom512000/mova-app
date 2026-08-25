<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileAccess;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileAccess>
 */
class ProfileAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileAccess::class);
    }

    public function findOneByPair(User $owner, User $viewer): ?ProfileAccess
    {
        return $this->findOneBy(['owner' => $owner, 'viewer' => $viewer]);
    }

    public function existsForPair(User $owner, User $viewer): bool
    {
        return null !== $this->findOneByPair($owner, $viewer);
    }

    /**
     * Profiles this viewer has been granted, for the header's profile switcher.
     *
     * @return User[]
     */
    public function findOwnersGrantedTo(User $viewer): array
    {
        return array_map(
            static fn (ProfileAccess $access) => $access->getOwner(),
            $this->createQueryBuilder('a')
                ->join('a.owner', 'o')
                ->addSelect('o')
                ->where('a.viewer = :viewer')
                ->setParameter('viewer', $viewer)
                ->orderBy('o.displayName', 'ASC')
                ->getQuery()
                ->getResult()
        );
    }
}
