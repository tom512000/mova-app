<?php

declare(strict_types=1);

namespace App\Service\Card;

/**
 * What one rebuild did, for the console command that calibrates the scoring.
 *
 * Not an API DTO — nothing serialises this. It exists because tuning the weights means
 * running the rebuild and reading the band distribution back, and a method that returned
 * void would make that a second query every time.
 */
final readonly class CardCatalogueReport
{
    /**
     * @param array<string, int> $bySubject   subject value => cards in catalogue
     * @param array<string, int> $byRarity    rarity value => cards in catalogue
     * @param list<array{label: string, subject: string, score: string}> $legendaries
     */
    public function __construct(
        public int $workCount,
        public int $cardCount,
        public int $relicCount,
        public array $bySubject,
        public array $byRarity,
        public array $legendaries,
        public float $seconds,
    ) {
    }
}
