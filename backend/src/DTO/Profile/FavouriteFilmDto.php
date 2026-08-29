<?php

declare(strict_types=1);

namespace App\DTO\Profile;

/**
 * One of the four films pinned to a Letterboxd profile.
 *
 * Carries its own poster and year rather than a movie id alone, so the block can be drawn
 * without a second round trip — four films is not worth a lookup per slot.
 */
final readonly class FavouriteFilmDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        /** 1-based, in the order Letterboxd lists them. */
        public int $position,
    ) {
    }
}
