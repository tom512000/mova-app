<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfileShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The shareable half of profile sharing: one long-lived token per owner that anyone can
 * be handed. Opening it doesn't itself reveal anything — it only lets a *logged-in*
 * visitor claim a ProfileAccess (see ProfileAccess), which is what actually grants reads.
 * Splitting the two means revoking one person's access doesn't invalidate the link for
 * everyone else, and rotating the link doesn't revoke anybody.
 */
#[ORM\Entity(repositoryClass: ProfileShareLinkRepository::class)]
#[ORM\Table(name: 'profile_share_link')]
#[ORM\UniqueConstraint(name: 'uniq_share_link_token', fields: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_share_link_owner', fields: ['owner'])]
class ProfileShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner)
    {
        $this->owner = $owner;
        $this->token = self::generateToken();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Invalidates the current link and mints a new one. Existing ProfileAccess rows are
     * untouched on purpose: rotating the link stops *new* people joining, it is not a
     * "kick everyone out" button.
     */
    public function rotate(): static
    {
        $this->token = self::generateToken();
        $this->createdAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function generateToken(): string
    {
        // A v4 UUID without dashes: 122 bits of randomness from a CSPRNG, which is well
        // past guessable, and URL-safe without any extra encoding.
        return str_replace('-', '', Uuid::v4()->toRfc4122());
    }
}
