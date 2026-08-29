<?php

declare(strict_types=1);

namespace App\DTO\Profile;

/**
 * The Letterboxd page behind an imported library.
 *
 * Every field but the dates is nullable because Letterboxd asks for none of them — a profile
 * with nothing but a username is perfectly normal, and the screen has to read well in that
 * case rather than showing a grid of dashes.
 */
final readonly class LetterboxdProfileDto
{
    /**
     * @param list<FavouriteFilmDto> $favourites in slot order, and empty when none are pinned
     */
    public function __construct(
        public ?string $username,
        public ?string $fullName,
        public ?string $location,
        public ?string $website,
        public ?string $bio,
        public ?string $pronoun,
        public ?string $joinedOn,
        public array $favourites,
        /** When the profile.csv this came from was imported. */
        public string $importedAt,
    ) {
    }
}
