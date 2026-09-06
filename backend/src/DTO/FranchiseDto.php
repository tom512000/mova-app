<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The saga a film belongs to, as shown on its page.
 *
 * Carries every film TMDB lists in the saga, not only the ones the library holds — naming
 * what is missing is the whole reason this exists. `watchedCount` is counted against
 * `films`, so the two can never disagree with the list under them.
 */
final readonly class FranchiseDto
{
    /**
     * @param list<FranchiseFilmDto> $films oldest first, undated entries last
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $watchedCount,
        public array $films,
    ) {
    }
}
