<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class ActivityStatsDto
{
    public function __construct(
        public int $activeDays,
        public int $spanDays,
        public int $busiestDayCount,
        public ?string $busiestDate,
        public int $longestStreakDays,
        /** @var WeekdayStatDto[] */
        public array $weekdays,
        /** @var ActivityDayDto[] one entry per day that has at least one watch */
        public array $calendar,
    ) {
    }
}
