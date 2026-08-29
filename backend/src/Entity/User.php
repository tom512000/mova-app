<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Owns everything personal in this app: watches, watchlist entries, CSV imports and the
 * Letterboxd RSS sync state. The TMDB-derived catalogue (Movie, Person, Genre, Country)
 * stays shared — two users who watched the same film point at the same Movie row.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use HasUuid;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $displayName;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /**
     * The public Letterboxd account this user syncs from, e.g. "tom51200". Lives here
     * rather than in LETTERBOXD_USERNAME so two users can sync two different accounts
     * through the one shared worker.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $letterboxdUsername = null;

    #[ORM\Column]
    private bool $rssSyncEnabled = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $displayName)
    {
        $this->initialiseUuid();
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getLetterboxdUsername(): ?string
    {
        return $this->letterboxdUsername;
    }

    public function setLetterboxdUsername(?string $letterboxdUsername): static
    {
        $this->letterboxdUsername = ('' !== $letterboxdUsername) ? $letterboxdUsername : null;

        return $this;
    }

    public function isRssSyncEnabled(): bool
    {
        return $this->rssSyncEnabled;
    }

    public function setRssSyncEnabled(bool $rssSyncEnabled): static
    {
        $this->rssSyncEnabled = $rssSyncEnabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * No plaintext password or salt is ever held on the entity, so there is nothing to
     * scrub — but the interface still requires the hook.
     */
    public function eraseCredentials(): void
    {
    }
}
