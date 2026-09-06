<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class FranchiseStatDto
{
    /**
     * @param int           $watchedCount films of the saga this profile has watched
     * @param int           $totalCount   films TMDB lists in it
     * @param list<string>  $missing      titles not yet watched, oldest first, capped for
     *                                    display — the count is watchedCount vs totalCount,
     *                                    never the length of this list
     */
    public function __construct(
        public string $franchiseId,
        public string $name,
        public int $watchedCount,
        public int $totalCount,
        public array $missing,
    ) {
    }
}
