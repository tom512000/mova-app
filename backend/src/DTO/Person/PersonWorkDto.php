<?php

declare(strict_types=1);

namespace App\DTO\Person;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;

/**
 * One work of this person's that the library holds, watched or not.
 *
 * Carries its roles as a list because a work can be credited to the same person several
 * times over — Philippe Lacheau writes, directs and stars in his own films, and three
 * separate rows for one title would read as three films.
 */
final readonly class PersonWorkDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public MediaType $mediaType,
        /** @var list<CreditRole> */
        public array $roles,
        /** Whoever they played, when they played somebody. Null for every crew credit. */
        public ?string $characterName,
        public ?float $myAverageRating,
        /**
         * Deduced rows excluded, the same reading the museum and "vus récemment" use: a
         * rating revised months later is a real note but not an evening in front of the film.
         */
        public ?string $lastWatchedDate,
        public bool $watched,
        public bool $inWatchlist,
    ) {
    }
}
