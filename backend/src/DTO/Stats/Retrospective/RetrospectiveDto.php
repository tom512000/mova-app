<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/**
 * One year of the library, told as a page rather than as a dashboard.
 *
 * Everything here is nullable or empty-able on purpose. A year with three viewings in it has
 * no meaningful busiest month and no genre that took over, and the page has to read as a
 * quiet year rather than as a broken one.
 */
final readonly class RetrospectiveDto
{
    public function __construct(
        public int $year,
        /** Viewings with a date, revised ratings excluded — the same reading the calendar uses. */
        public int $watchCount,
        /** Distinct works, so three rewatches of one film are one film. */
        public int $workCount,
        public int $activeDays,
        public int $totalRuntimeMinutes,
        /** Named so the hours can be read as a floor rather than as a total. */
        public int $worksWithoutRuntime,
        public ?float $averageRating,
        public ?RetrospectiveMonthDto $busiestMonth,
        public ?RetrospectiveStreakDto $longestStreak,
        public ?GenreShiftDto $genre,
        /**
         * One entry per job, direction first.
         *
         * @var list<PersonOfTheYearDto>
         */
        public array $people,
        /** The oldest film watched for the first time this year. */
        public ?RetrospectiveWorkDto $oldestDiscovery,
        public ?YearComparisonDto $previousYear,
        /**
         * Best-rated first, capped for display.
         *
         * @var list<RetrospectiveWorkDto>
         */
        public array $topRated,
    ) {
    }
}
