<?php

declare(strict_types=1);

namespace App\DTO\Stats;

use App\Entity\Enum\CreditRole;

/**
 * Somebody met for the first time in a given year, and what came of it.
 *
 * Not the same question as the retrospective's person of the year, which asks who filled a
 * year whether or not they were new. This one only counts people with no earlier work at
 * all in the library: the year they walked in, and how much of them has been watched since.
 *
 * On a diary only two years deep the two answers mostly agree, because almost everybody is
 * new. They come apart as a library ages, which is when this block starts earning its place.
 */
final readonly class DiscoveryDto
{
    public function __construct(
        public string $personId,
        public string $name,
        public ?string $profileUrl,
        /**
         * Direction wins over performance when somebody does both, which is the stronger
         * claim of the two: an actor-director is discovered as a director.
         */
        public CreditRole $role,
        /** Works of theirs watched, all of them — the count is what makes it a discovery. */
        public int $workCount,
        public ?float $averageRating,
        /** The day of the first one, which is the day they were met. */
        public string $firstSeenOn,
    ) {
    }
}
