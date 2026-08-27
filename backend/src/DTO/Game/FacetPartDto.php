<?php

declare(strict_types=1);

namespace App\DTO\Game;

use App\Entity\Enum\FacetMatch;

/**
 * One value inside a list-shaped attribute — a single genre, a single studio — judged on
 * its own. Only EXACT and NONE occur here: a genre either is one of the answer's or is not.
 */
final readonly class FacetPartDto
{
    public function __construct(
        public string $value,
        public FacetMatch $match,
    ) {
    }
}
