<?php

declare(strict_types=1);

namespace App\DTO\Trophy;

use App\Entity\Enum\Trophy;
use App\Entity\Enum\TrophyFamily;

/**
 * One trophy, won or not.
 *
 * Locked trophies are returned too, unlike badges. There are hundreds of possible badges and
 * listing the ones not earned would be listing the whole catalogue of every genre and every
 * actor on TMDB; there are fourteen trophies, and "Seul au monde" is more interesting as
 * something to go and get than as something that silently is not there.
 *
 * Stored nowhere, like badges: every field is derived from the watch dates on request, so
 * the date a trophy was won is the date of the evening that won it, including for the
 * hundreds of evenings imported long before trophies existed.
 */
final readonly class TrophyDto
{
    public function __construct(
        public Trophy $key,
        public TrophyFamily $family,
        /** @var list<int> */
        public array $tiers,
        /**
         * The figure the rungs are measured against: the longest run of days, the number of
         * full weekends, the longest pause, the years elapsed, the days that fell on the date.
         * Reported whether or not anything was won, so a locked trophy can say how close it is.
         */
        public int $value,
        /** Rungs reached. Zero means locked. */
        public int $level,
        /** The day the current rung was reached. Null while locked. */
        public ?string $earnedOn,
    ) {
    }
}
