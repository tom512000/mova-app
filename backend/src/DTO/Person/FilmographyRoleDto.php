<?php

declare(strict_types=1);

namespace App\DTO\Person;

use App\Entity\Enum\CreditRole;

/**
 * "Tu en as vu 9 sur 14", for one job.
 *
 * Split per job rather than given as one number because a single total would be meaningless
 * for anyone who does more than one thing: Christopher Nolan has a hundred and twelve film
 * credits at TMDB and directed fourteen of them, and only the second figure is what somebody
 * means when they ask how much of his work they have seen.
 */
final readonly class FilmographyRoleDto
{
    public function __construct(
        public CreditRole $role,
        /** Films of this filmography the profile has watched. */
        public int $watchedCount,
        /** Films TMDB credits them with in this job, once the noise is filtered out. */
        public int $totalCount,
        /**
         * Unwatched titles, most recent first, capped for display — the count above is
         * watchedCount against totalCount, never the length of this list.
         *
         * @var list<FilmographyEntryDto>
         */
        public array $missing,
    ) {
    }
}
