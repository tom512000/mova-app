<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\WatchlistSortField;

final readonly class WatchlistSearchCriteria
{
    /**
     * @param string|null $query      matched against the title
     * @param int|null    $maxRuntime the evening's budget in minutes: keeps what fits inside
     *                                it. A work whose runtime is unknown is left out rather
     *                                than offered on the chance it might fit — the question
     *                                being asked is "what can I finish tonight", and an
     *                                unknown length cannot answer it
     * @param int|null    $decade     the first year of the decade, e.g. 1990 for the nineties
     */
    public function __construct(
        public ?string $query = null,
        public ?int $maxRuntime = null,
        public ?string $genre = null,
        public ?int $decade = null,
        public WatchlistSortField $sort = WatchlistSortField::ADDED,
        public bool $descending = true,
        public int $page = 1,
        public int $perPage = 24,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
