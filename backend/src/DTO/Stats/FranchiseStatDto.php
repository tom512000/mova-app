<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class FranchiseStatDto
{
    /**
     * @param int          $watchedCount  films of the saga this profile has watched
     * @param int          $totalCount    films counted in it, which is not always what TMDB
     *                                    lists: an announced film nobody can have watched is
     *                                    left out unless the caller asked for it
     * @param int          $upcomingCount films of the saga that are not out yet, reported
     *                                    whether or not they were counted above — a saga
     *                                    finished but for next year's sequel is worth saying
     * @param list<string> $missing       titles counted and not watched, oldest first, capped
     *                                    for display — the tally is watchedCount against
     *                                    totalCount, never the length of this list
     * @param list<string> $upcoming      titles not out yet, same order and same cap
     */
    public function __construct(
        public string $franchiseId,
        public string $name,
        public int $watchedCount,
        public int $totalCount,
        public int $upcomingCount,
        public array $missing,
        public array $upcoming,
    ) {
    }
}
