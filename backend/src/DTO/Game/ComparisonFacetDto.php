<?php

declare(strict_types=1);

namespace App\DTO\Game;

use App\Entity\Enum\FacetMatch;

/**
 * One attribute of a guessed film, already judged against the answer. The comparison is
 * done server-side on purpose: sending the answer's values so the client could diff them
 * would hand over the very thing being guessed.
 */
final readonly class ComparisonFacetDto
{
    /**
     * @param string|null          $direction 'up' when the answer's value is higher, 'down'
     *                                        when it is lower; null for anything that is not
     *                                        a number
     * @param list<FacetPartDto>|null $parts  for list-shaped attributes, each value judged on
     *                                        its own — a film sharing one genre out of three
     *                                        should show which one. Null for the attributes
     *                                        that hold a single number.
     */
    public function __construct(
        public string $label,
        public string $value,
        public FacetMatch $match,
        public ?string $direction = null,
        public ?array $parts = null,
    ) {
    }
}
