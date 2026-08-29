<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\WatchSource;
use App\Repository\WatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One occurrence of ME watching a Movie. A rewatch creates a new Watch row,
 * never overwrites the previous one, so rating history per movie is preserved.
 */
#[ORM\Entity(repositoryClass: WatchRepository::class)]
#[ORM\Table(name: 'watch')]
#[ORM\UniqueConstraint(name: 'uniq_watch_user_external_ref', fields: ['user', 'externalRef'])]
#[ORM\Index(name: 'idx_watch_watched_date', fields: ['watchedDate'])]
#[ORM\Index(name: 'idx_watch_movie', fields: ['movie'])]
#[ORM\Index(name: 'idx_watch_user', fields: ['user'])]
class Watch
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Movie::class, inversedBy: 'watches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $watchedDate = null;

    /** 0.5 to 5.0 by 0.5 steps, matching the Letterboxd star scale. */
    #[ORM\Column(type: Types::DECIMAL, precision: 2, scale: 1, nullable: true)]
    private ?string $rating = null;

    #[ORM\Column]
    private bool $isRewatch = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewText = null;

    #[ORM\Column]
    private bool $containsSpoilers = false;

    #[ORM\Column(length: 20, enumType: WatchSource::class)]
    private WatchSource $source;

    /**
     * Idempotency key: the Letterboxd diary entry URI for CSV imports,
     * or the RSS item guid for RSS-synced watches. Null for rows that
     * only ever appeared in ratings.csv/watched.csv (one per movie, enforced
     * at the service level instead since those files have no per-row URI).
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'watch_tag')]
    private Collection $tags;

    public function __construct(User $user, Movie $movie, WatchSource $source)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->movie = $movie;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getWatchedDate(): ?\DateTimeImmutable
    {
        return $this->watchedDate;
    }

    public function setWatchedDate(?\DateTimeImmutable $watchedDate): static
    {
        $this->watchedDate = $watchedDate;

        return $this;
    }

    public function getRating(): ?float
    {
        return null === $this->rating ? null : (float) $this->rating;
    }

    public function setRating(?float $rating): static
    {
        $this->rating = null === $rating ? null : number_format($rating, 1, '.', '');

        return $this;
    }

    public function isRewatch(): bool
    {
        return $this->isRewatch;
    }

    public function setIsRewatch(bool $isRewatch): static
    {
        $this->isRewatch = $isRewatch;

        return $this;
    }

    public function getReviewText(): ?string
    {
        return $this->reviewText;
    }

    public function setReviewText(?string $reviewText): static
    {
        $this->reviewText = $reviewText;

        return $this;
    }

    public function hasSpoilers(): bool
    {
        return $this->containsSpoilers;
    }

    public function setContainsSpoilers(bool $containsSpoilers): static
    {
        $this->containsSpoilers = $containsSpoilers;

        return $this;
    }

    public function getSource(): WatchSource
    {
        return $this->source;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    public function setExternalRef(?string $externalRef): static
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function clearTags(): static
    {
        $this->tags->clear();

        return $this;
    }
}
