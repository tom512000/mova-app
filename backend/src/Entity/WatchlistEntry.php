<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\WatchlistEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WatchlistEntryRepository::class)]
#[ORM\Table(name: 'watchlist_entry')]
#[ORM\UniqueConstraint(name: 'uniq_watchlist_entry_user_movie', fields: ['user', 'movie'])]
#[ORM\Index(name: 'idx_watchlist_entry_user', fields: ['user'])]
class WatchlistEntry
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $addedDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Movie $movie)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->movie = $movie;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getAddedDate(): ?\DateTimeImmutable
    {
        return $this->addedDate;
    }

    public function setAddedDate(?\DateTimeImmutable $addedDate): static
    {
        $this->addedDate = $addedDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
