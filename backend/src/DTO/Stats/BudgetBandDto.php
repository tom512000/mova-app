<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class BudgetBandDto
{
    /**
     * Bounds rather than a label: the wording belongs with the rest of the French copy, in
     * the frontend, and "less than five million" is derivable from a min of 0 anyway.
     *
     * @param int      $minBudget inclusive, in US dollars; 0 on the first band
     * @param int|null $maxBudget exclusive; null on the last one, which is open-ended
     */
    public function __construct(
        public int $minBudget,
        public ?int $maxBudget,
        public int $movieCount,
        public ?float $averageRating,
    ) {
    }
}
