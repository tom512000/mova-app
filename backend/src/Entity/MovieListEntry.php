<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One film or series on a list, at its place in it.
 *
 * Unique on (list, movie) and deliberately *not* on (list, position). Letterboxd will not put
 * the same film on a list twice, so the first constraint states a fact. The second would
 * state one too, but a re-import that reorders a list moves positions between rows that all
 * still exist, and Doctrine flushes updates in an order no constraint was consulted on — two
 * films swapping places would collide halfway through and fail the whole import.
 */
#[ORM\Entity]
#[ORM\Table(name: 'movie_list_entry')]
#[ORM\UniqueConstraint(name: 'uniq_movie_list_entry_list_movie', fields: ['list', 'movie'])]
#[ORM\Index(name: 'idx_movie_list_entry_list_position', fields: ['list', 'position'])]
#[ORM\Index(name: 'idx_movie_list_entry_movie', fields: ['movie'])]
class MovieListEntry
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: MovieList::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MovieList $list;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    /** 1-based, as the export numbers it. */
    #[ORM\Column]
    private int $position;

    /** The per-film note a list can carry beside each entry (the export's "Description"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(MovieList $list, Movie $movie, int $position)
    {
        $this->initialiseUuid();
        $this->list = $list;
        $this->movie = $movie;
        $this->position = $position;
    }

    public function getList(): MovieList
    {
        return $this->list;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }
}
