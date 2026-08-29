<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'person')]
#[ORM\UniqueConstraint(name: 'uniq_person_tmdb_id', fields: ['tmdbId'])]
class Person
{
    use HasUuid;

    #[ORM\Column(nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profilePath = null;

    /**
     * Read-only from this side — Credit lifecycle is managed via Movie::$credits
     * (orphanRemoval lives there), not here, to avoid both sides fighting over it.
     *
     * @var Collection<int, Credit>
     */
    #[ORM\OneToMany(targetEntity: Credit::class, mappedBy: 'person')]
    private Collection $credits;

    public function __construct()
    {
        $this->initialiseUuid();
        $this->credits = new ArrayCollection();
    }

    public function getTmdbId(): ?int
    {
        return $this->tmdbId;
    }

    public function setTmdbId(?int $tmdbId): static
    {
        $this->tmdbId = $tmdbId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getProfilePath(): ?string
    {
        return $this->profilePath;
    }

    public function setProfilePath(?string $profilePath): static
    {
        $this->profilePath = $profilePath;

        return $this;
    }

    /** @return Collection<int, Credit> */
    public function getCredits(): Collection
    {
        return $this->credits;
    }
}
