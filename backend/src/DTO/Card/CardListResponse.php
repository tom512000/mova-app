<?php

declare(strict_types=1);

namespace App\DTO\Card;

/**
 * A page of the album.
 *
 * The same envelope BadgeListResponse uses, and for the same reason: one endpoint serves
 * both the paged grid and the counters above it, so `counts` rides along — but only on an
 * unfiltered read, since a count of what a filter already excluded is noise.
 */
final readonly class CardListResponse
{
    /**
     * @param list<CardDto>      $items
     * @param array<string, int>|null $counts rarity value => cards, or null when filtered
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public ?array $counts,
    ) {
    }
}
