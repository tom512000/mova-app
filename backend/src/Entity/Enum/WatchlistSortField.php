<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How a watchlist is put in order. Deliberately a shorter list than MovieSortField: nothing
 * here has been watched, so there is no rating and no viewing date to sort on.
 */
enum WatchlistSortField: string
{
    /** How long it has been sitting there — the reason the sort exists. */
    case ADDED = 'added';
    case TITLE = 'title';
    case YEAR = 'year';
    case RUNTIME = 'runtime';

    public function defaultsToDescending(): bool
    {
        // Newest first for a date, shortest first for a runtime you are trying to fit into an
        // evening, A to Z for a title.
        return self::ADDED === $this;
    }

    /** A DQL property path, over the aliases WatchlistEntryRepository::search() uses. */
    public function orderBy(): string
    {
        return match ($this) {
            self::ADDED => 'we.addedDate',
            self::TITLE => 'm.title',
            self::YEAR => 'm.releaseYear',
            self::RUNTIME => 'm.runtimeMinutes',
        };
    }
}
