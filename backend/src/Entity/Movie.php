<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\EnrichmentStatus;
use App\Repository\MovieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovieRepository::class)]
#[ORM\Table(name: 'movie')]
#[ORM\UniqueConstraint(name: 'uniq_movie_letterboxd_slug', fields: ['letterboxdSlug'])]
#[ORM\UniqueConstraint(name: 'uniq_movie_tmdb_id', fields: ['tmdbId'])]
#[ORM\Index(name: 'idx_movie_enrichment_status', fields: ['enrichmentStatus'])]
class Movie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $imdbId = null;

    /**
     * Slug extracted from the Letterboxd film URI, e.g. "dune-part-two".
     * This is the anchor used for idempotent imports since Letterboxd
     * exports never expose a stable numeric film id.
     */
    #[ORM\Column(length: 255)]
    private string $letterboxdSlug;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $originalTitle = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    /**
     * Kept even when releaseDate is unknown: the CSV export always gives a year,
     * TMDB search matching is scored against it before a full releaseDate exists.
     */
    #[ORM\Column(nullable: true)]
    private ?int $releaseYear = null;

    #[ORM\Column(nullable: true)]
    private ?int $runtimeMinutes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $synopsis = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $originalLanguage = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $budget = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $revenue = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $popularity = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $tmdbVoteAverage = null;

    #[ORM\Column(nullable: true)]
    private ?int $tmdbVoteCount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $posterPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $backdropPath = null;

    #[ORM\Column(length: 20, enumType: EnrichmentStatus::class)]
    private EnrichmentStatus $enrichmentStatus = EnrichmentStatus::PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $enrichmentError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastEnrichmentAttemptAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Genre> */
    #[ORM\ManyToMany(targetEntity: Genre::class)]
    #[ORM\JoinTable(name: 'movie_genre')]
    private Collection $genres;

    /** @var Collection<int, Country> */
    #[ORM\ManyToMany(targetEntity: Country::class)]
    #[ORM\JoinTable(name: 'movie_country')]
    private Collection $countries;

    /** @var Collection<int, Credit> */
    #[ORM\OneToMany(targetEntity: Credit::class, mappedBy: 'movie', orphanRemoval: true, cascade: ['persist'])]
    private Collection $credits;

    /** @var Collection<int, Watch> */
    #[ORM\OneToMany(targetEntity: Watch::class, mappedBy: 'movie', orphanRemoval: true, cascade: ['persist'])]
    private Collection $watches;

    public function __construct(string $letterboxdSlug, string $title)
    {
        $this->letterboxdSlug = $letterboxdSlug;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->genres = new ArrayCollection();
        $this->countries = new ArrayCollection();
        $this->credits = new ArrayCollection();
        $this->watches = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getImdbId(): ?string
    {
        return $this->imdbId;
    }

    public function setImdbId(?string $imdbId): static
    {
        $this->imdbId = $imdbId;

        return $this;
    }

    public function getLetterboxdSlug(): string
    {
        return $this->letterboxdSlug;
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

    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }

    public function setOriginalTitle(?string $originalTitle): static
    {
        $this->originalTitle = $originalTitle;

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

    public function getReleaseYear(): ?int
    {
        return $this->releaseYear;
    }

    public function setReleaseYear(?int $releaseYear): static
    {
        $this->releaseYear = $releaseYear;

        return $this;
    }

    public function getRuntimeMinutes(): ?int
    {
        return $this->runtimeMinutes;
    }

    public function setRuntimeMinutes(?int $runtimeMinutes): static
    {
        $this->runtimeMinutes = $runtimeMinutes;

        return $this;
    }

    public function getSynopsis(): ?string
    {
        return $this->synopsis;
    }

    public function setSynopsis(?string $synopsis): static
    {
        $this->synopsis = $synopsis;

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getOriginalLanguage(): ?string
    {
        return $this->originalLanguage;
    }

    public function setOriginalLanguage(?string $originalLanguage): static
    {
        $this->originalLanguage = $originalLanguage;

        return $this;
    }

    public function getBudget(): ?string
    {
        return $this->budget;
    }

    public function setBudget(?string $budget): static
    {
        $this->budget = $budget;

        return $this;
    }

    public function getRevenue(): ?string
    {
        return $this->revenue;
    }

    public function setRevenue(?string $revenue): static
    {
        $this->revenue = $revenue;

        return $this;
    }

    public function getPopularity(): ?float
    {
        return $this->popularity;
    }

    public function setPopularity(?float $popularity): static
    {
        $this->popularity = $popularity;

        return $this;
    }

    public function getTmdbVoteAverage(): ?float
    {
        return $this->tmdbVoteAverage;
    }

    public function setTmdbVoteAverage(?float $tmdbVoteAverage): static
    {
        $this->tmdbVoteAverage = $tmdbVoteAverage;

        return $this;
    }

    public function getTmdbVoteCount(): ?int
    {
        return $this->tmdbVoteCount;
    }

    public function setTmdbVoteCount(?int $tmdbVoteCount): static
    {
        $this->tmdbVoteCount = $tmdbVoteCount;

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

    public function getBackdropPath(): ?string
    {
        return $this->backdropPath;
    }

    public function setBackdropPath(?string $backdropPath): static
    {
        $this->backdropPath = $backdropPath;

        return $this;
    }

    public function getEnrichmentStatus(): EnrichmentStatus
    {
        return $this->enrichmentStatus;
    }

    public function setEnrichmentStatus(EnrichmentStatus $enrichmentStatus): static
    {
        $this->enrichmentStatus = $enrichmentStatus;

        return $this;
    }

    public function getEnrichmentError(): ?string
    {
        return $this->enrichmentError;
    }

    public function setEnrichmentError(?string $enrichmentError): static
    {
        $this->enrichmentError = $enrichmentError;

        return $this;
    }

    public function getLastEnrichmentAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastEnrichmentAttemptAt;
    }

    public function setLastEnrichmentAttemptAt(?\DateTimeImmutable $lastEnrichmentAttemptAt): static
    {
        $this->lastEnrichmentAttemptAt = $lastEnrichmentAttemptAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Strips every TMDB-derived field, leaving only what the Letterboxd export itself
     * provided (slug, title, watched year). Used when a match turns out to be wrong:
     * the stale poster/runtime/credits of the *other* film must go, otherwise they keep
     * feeding the stats (a 5-minute short winning "shortest film", say).
     */
    public function clearTmdbData(): static
    {
        $this->tmdbId = null;
        $this->imdbId = null;
        $this->originalTitle = null;
        $this->releaseDate = null;
        $this->runtimeMinutes = null;
        $this->synopsis = null;
        $this->tagline = null;
        $this->originalLanguage = null;
        $this->budget = null;
        $this->revenue = null;
        $this->popularity = null;
        $this->tmdbVoteAverage = null;
        $this->tmdbVoteCount = null;
        $this->posterPath = null;
        $this->backdropPath = null;
        $this->clearGenres();
        $this->clearCountries();
        $this->clearCredits();

        return $this->touch();
    }

    /** @return Collection<int, Genre> */
    public function getGenres(): Collection
    {
        return $this->genres;
    }

    public function addGenre(Genre $genre): static
    {
        if (!$this->genres->contains($genre)) {
            $this->genres->add($genre);
        }

        return $this;
    }

    public function clearGenres(): static
    {
        $this->genres->clear();

        return $this;
    }

    /** @return Collection<int, Country> */
    public function getCountries(): Collection
    {
        return $this->countries;
    }

    public function addCountry(Country $country): static
    {
        if (!$this->countries->contains($country)) {
            $this->countries->add($country);
        }

        return $this;
    }

    public function clearCountries(): static
    {
        $this->countries->clear();

        return $this;
    }

    /** @return Collection<int, Credit> */
    public function getCredits(): Collection
    {
        return $this->credits;
    }

    public function addCredit(Credit $credit): static
    {
        if (!$this->credits->contains($credit)) {
            $this->credits->add($credit);
        }

        return $this;
    }

    public function clearCredits(): static
    {
        $this->credits->clear();

        return $this;
    }

    /** @return Collection<int, Watch> */
    public function getWatches(): Collection
    {
        return $this->watches;
    }

    public function addWatch(Watch $watch): static
    {
        if (!$this->watches->contains($watch)) {
            $this->watches->add($watch);
        }

        return $this;
    }
}
