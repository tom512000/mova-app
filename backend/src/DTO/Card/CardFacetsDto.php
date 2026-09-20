<?php

declare(strict_types=1);

namespace App\DTO\Card;

/**
 * What the album holds, counted every way the filter bar offers.
 *
 * Owned counts and totals travel together because the interesting number is always the
 * fraction: "37 des 573 Rares", not either half alone.
 */
final readonly class CardFacetsDto
{
    /**
     * @param array<string, int> $byRarity       rarity value => cards in catalogue
     * @param array<string, int> $ownedByRarity  rarity value => cards owned
     * @param array<string, int> $bySubject      subject value => cards in catalogue
     * @param array<string, int> $ownedBySubject subject value => cards owned
     */
    public function __construct(
        public array $byRarity,
        public array $ownedByRarity,
        public array $bySubject,
        public array $ownedBySubject,
        public int $total,
        public int $owned,
        /** Owned cards whose subject has left the library: kept, but no longer drawable. */
        public int $outOfCatalogue,
    ) {
    }
}
