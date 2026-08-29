<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\StudioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A TMDB production company. Kept as its own table rather than a string on Movie so the
 * same studio is one row across the library.
 */
#[ORM\Entity(repositoryClass: StudioRepository::class)]
#[ORM\Table(name: 'studio')]
#[ORM\UniqueConstraint(name: 'uniq_studio_tmdb_id', fields: ['tmdbId'])]
class Studio
{
    use HasUuid;

    #[ORM\Column]
    private int $tmdbId;

    #[ORM\Column(length: 200)]
    private string $name;

    public function __construct()
    {
        $this->initialiseUuid();
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
}
