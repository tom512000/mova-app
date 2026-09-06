<?php

declare(strict_types=1);

namespace App\DTO\Person;

/**
 * A person's page, built entirely from what the library already knows.
 *
 * What TMDB would have to be asked for — how many films they made in total, and therefore
 * how many are missing — is not here: it arrives separately, so the page draws immediately
 * and the count that needs the network fills in after. See PersonFilmographyService.
 */
final readonly class PersonProfileDto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $tmdbId,
        public ?string $profileUrl,
        /**
         * Every job held, most-credited first.
         *
         * @var list<PersonRoleDto>
         */
        public array $roles,
        /** Distinct works watched, all roles together — not the sum of the roles above. */
        public int $watchedCount,
        /** Works credited to them sitting in the watchlist, watched or not. */
        public int $watchlistCount,
        /** Across every watched work, whatever the job. Null until one of them is rated. */
        public ?float $averageRating,
        /**
         * How that compares to the profile's own average, in stars. Positive means this
         * person is rated above the library at large. Null when either side is unrated.
         */
        public ?float $ratingGap,
        /**
         * Every work of theirs the library holds, newest first.
         *
         * @var list<PersonWorkDto>
         */
        public array $works,
    ) {
    }
}
