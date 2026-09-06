<?php

declare(strict_types=1);

namespace App\DTO\Person;

/**
 * What TMDB says this person made, against what the profile has seen of it.
 *
 * Separate from PersonProfileDto because it is the one part of the page that needs the
 * network: the profile itself is answered from the library and draws immediately, and this
 * fills in behind it.
 */
final readonly class PersonFilmographyDto
{
    public function __construct(
        /**
         * One entry per job the library already credits them with, in the same order the
         * page lists those jobs.
         *
         * @var list<FilmographyRoleDto>
         */
        public array $roles,
        /**
         * Says out loud what was counted, because the rule is not obvious and the numbers
         * would otherwise look wrong to anyone who checked them against TMDB.
         */
        public string $note,
    ) {
    }
}
