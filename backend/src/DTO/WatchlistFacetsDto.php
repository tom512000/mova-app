<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * What the watchlist can actually be narrowed by — read from the watchlist itself, not from
 * the library, so no dropdown ever offers a choice that empties the page.
 *
 * The runtime bounds are what makes "I have an hour and a half" answerable: the interface can
 * tell whether a time budget would leave anything at all, and say so before the grid is empty.
 */
final readonly class WatchlistFacetsDto
{
    /**
     * @param list<string> $genres
     * @param list<int>    $decades first year of each decade present, newest first
     */
    public function __construct(
        public array $genres,
        public array $decades,
        public ?int $shortestRuntime,
        public ?int $longestRuntime,
    ) {
    }
}
