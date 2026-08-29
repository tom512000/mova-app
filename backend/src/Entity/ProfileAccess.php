<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\ProfileAccessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "viewer is allowed to read owner's profile", created when someone opens an owner's
 * ProfileShareLink while logged in. Read-only by construction: nothing in the app ever
 * writes through a ProfileAccess, and the import/sync endpoints ignore the viewed profile
 * entirely and always act on the authenticated user.
 */
#[ORM\Entity(repositoryClass: ProfileAccessRepository::class)]
#[ORM\Table(name: 'profile_access')]
#[ORM\UniqueConstraint(name: 'uniq_profile_access_pair', fields: ['owner', 'viewer'])]
#[ORM\Index(name: 'idx_profile_access_viewer', fields: ['viewer'])]
class ProfileAccess
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $viewer;

    #[ORM\Column]
    private \DateTimeImmutable $grantedAt;

    public function __construct(User $owner, User $viewer)
    {
        $this->initialiseUuid();
        $this->owner = $owner;
        $this->viewer = $viewer;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getViewer(): User
    {
        return $this->viewer;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
