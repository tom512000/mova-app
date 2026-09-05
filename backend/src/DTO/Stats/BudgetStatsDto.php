<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class BudgetStatsDto
{
    /**
     * @param BudgetBandDto[] $bands              always all of them, in ascending order, a
     *                                            band nobody watched included at zero
     * @param int             $worksWithoutBudget watched works TMDB has no budget for, which
     *                                            is what the bands were NOT computed from
     */
    public function __construct(
        public array $bands,
        public int $worksWithoutBudget,
    ) {
    }
}
