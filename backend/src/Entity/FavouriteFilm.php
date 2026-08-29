<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the four films pinned to the top of a Letterboxd profile.
 *
 * A join row rather than a plain many-to-many because the order is the point: these are
 * numbered slots somebody arranged, not a set. The unique constraint is over the pair
 * (profile, position) so two films can never claim the same slot.
 */
#[ORM\Entity]
#[ORM\Table(name: 'favourite_film')]
#[ORM\UniqueConstraint(name: 'uniq_favourite_film_slot', fields: ['profile', 'position'])]
class FavouriteFilm
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: LetterboxdProfile::class, inversedBy: 'favourites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LetterboxdProfile $profile;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    /** 1-based, in the order profile.csv lists them — which is the order they are displayed. */
    #[ORM\Column]
    private int $position;

    public function __construct(LetterboxdProfile $profile, Movie $movie, int $position)
    {
        $this->initialiseUuid();
        $this->profile = $profile;
        $this->movie = $movie;
        $this->position = $position;
    }

    public function getProfile(): LetterboxdProfile
    {
        return $this->profile;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
