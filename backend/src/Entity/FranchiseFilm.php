<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\FranchiseFilmRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of a TMDB franchise, held whether or not the library owns it.
 *
 * Reference data, not a work in the library: no rating, no viewing, nothing a profile ever
 * writes to. It exists so "you have four of the seven" can name the other three, which is the
 * only version of that sentence worth reading.
 *
 * The link back to a Movie is by tmdbId at read time rather than a foreign key. A row here is
 * a fact about the franchise and outlives whatever the library happens to hold today — a film
 * imported next month should join its franchise without this table being rewritten.
 */
#[ORM\Entity(repositoryClass: FranchiseFilmRepository::class)]
#[ORM\Table(name: 'franchise_film')]
#[ORM\Index(name: 'idx_franchise_film_franchise', fields: ['franchise'])]
#[ORM\UniqueConstraint(name: 'uniq_franchise_film_tmdb_id', fields: ['franchise', 'tmdbId'])]
class FranchiseFilm
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: Franchise::class, inversedBy: 'films')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Franchise $franchise;

    #[ORM\Column]
    private int $tmdbId;

    #[ORM\Column(length: 500)]
    private string $title;

    /**
     * Null on an announced film TMDB has no date for yet, which is common in a live franchise
     * and is exactly the sort of row that must not be dropped: an unreleased sequel is a
     * legitimate answer to "what is left".
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    #[ORM\Column(nullable: true)]
    private ?string $posterPath = null;

    public function __construct(Franchise $franchise, int $tmdbId, string $title)
    {
        $this->initialiseUuid();
        $this->franchise = $franchise;
        $this->tmdbId = $tmdbId;
        $this->title = $title;
    }

    public function getFranchise(): Franchise
    {
        return $this->franchise;
    }

    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getReleaseDate(): ?\DateTimeImmutable
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(?\DateTimeImmutable $releaseDate): static
    {
        $this->releaseDate = $releaseDate;

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
}
