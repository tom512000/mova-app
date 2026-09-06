<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\FranchiseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A TMDB film collection — what it calls a saga in French, and what everybody else calls a
 * franchise: Jurassic Park, Indiana Jones, L'Âge de glace.
 *
 * Named Franchise rather than Collection because Doctrine's Collection is imported into every
 * entity in this codebase, and two things called Collection in one file is a trap laid for
 * whoever edits it next. TMDB's own name for it survives in the mapper and the client.
 *
 * Films only. TMDB has no equivalent for series — a `belongs_to_collection` field simply does
 * not exist on /tv — so a series never carries one, and no amount of enrichment will change
 * that.
 */
#[ORM\Entity(repositoryClass: FranchiseRepository::class)]
#[ORM\Table(name: 'franchise')]
#[ORM\UniqueConstraint(name: 'uniq_franchise_tmdb_id', fields: ['tmdbId'])]
class Franchise
{
    use HasUuid;

    #[ORM\Column]
    private int $tmdbId;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(nullable: true)]
    private ?string $posterPath = null;

    /**
     * Every film TMDB lists in the franchise, whether or not the library holds it — which is
     * the whole point: the missing ones are the interesting half.
     *
     * @var Collection<int, FranchiseFilm>
     */
    #[ORM\OneToMany(targetEntity: FranchiseFilm::class, mappedBy: 'franchise', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['releaseDate' => 'ASC', 'tmdbId' => 'ASC'])]
    private Collection $films;

    public function __construct()
    {
        $this->initialiseUuid();
        $this->films = new ArrayCollection();
    }

    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }

    public function setTmdbId(int $tmdbId): static
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

    public function getPosterPath(): ?string
    {
        return $this->posterPath;
    }

    public function setPosterPath(?string $posterPath): static
    {
        $this->posterPath = $posterPath;

        return $this;
    }

    /** @return Collection<int, FranchiseFilm> */
    public function getFilms(): Collection
    {
        return $this->films;
    }

    public function addFilm(FranchiseFilm $film): static
    {
        if (!$this->films->contains($film)) {
            $this->films->add($film);
        }

        return $this;
    }

    public function clearFilms(): static
    {
        $this->films->clear();

        return $this;
    }
}
