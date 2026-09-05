<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\ActivityDayDto;
use App\DTO\Stats\ActivityStatsDto;
use App\DTO\Stats\WeekdayStatDto;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Viewing rhythm: which days of the week carry the habit, and a day-by-day calendar.
 *
 * Both read watched_date. For a library imported from ratings.csv that column holds the date
 * the rating was logged rather than the viewing itself — accurate for anyone who rates right
 * after watching, and off by however long they wait otherwise.
 *
 * Both also skip the rows deduced from a moved rating date. Revising a note is not an evening
 * spent watching something, and counting it as one would put a square on the calendar for a
 * day with no film behind it — a square that now opens the library filtered on that day, and
 * would open it empty.
 */
final class ActivityStatsService
{
    /** ISO-8601 weekday numbering, so 1 is Monday and 7 is Sunday. */
    private const WEEKDAY_LABELS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getActivity(User $user): ActivityStatsDto
    {
        $conn = $this->entityManager->getConnection();
        $userId = (string) $user->getId();

        $weekdayRows = $conn->executeQuery(
            'SELECT
                EXTRACT(ISODOW FROM watched_date) AS weekday,
                COUNT(*) AS watch_count,
                AVG(rating) AS average_rating
            FROM watch
            WHERE user_id = :userId AND watched_date IS NOT NULL AND source <> :deduced
            GROUP BY weekday',
            ['userId' => $userId, 'deduced' => WatchSource::CSV_RERATING->value]
        )->fetchAllAssociative();

        $byWeekday = [];
        foreach ($weekdayRows as $row) {
            $byWeekday[(int) $row['weekday']] = $row;
        }

        $weekdays = [];
        foreach (self::WEEKDAY_LABELS as $number => $label) {
            $row = $byWeekday[$number] ?? null;
            $weekdays[] = new WeekdayStatDto(
                weekday: $number,
                label: $label,
                watchCount: null !== $row ? (int) $row['watch_count'] : 0,
                averageRating: (null !== $row && null !== $row['average_rating'])
                    ? round((float) $row['average_rating'], 2)
                    : null,
            );
        }

        $dayRows = $conn->executeQuery(
            'SELECT watched_date, COUNT(*) AS watch_count
            FROM watch
            WHERE user_id = :userId AND watched_date IS NOT NULL AND source <> :deduced
            GROUP BY watched_date
            ORDER BY watched_date ASC',
            ['userId' => $userId, 'deduced' => WatchSource::CSV_RERATING->value]
        )->fetchAllAssociative();

        $calendar = array_map(
            static fn (array $row) => new ActivityDayDto(
                date: $row['watched_date'],
                watchCount: (int) $row['watch_count'],
            ),
            $dayRows
        );

        $busiest = null;
        foreach ($calendar as $day) {
            if (null === $busiest || $day->watchCount > $busiest->watchCount) {
                $busiest = $day;
            }
        }

        return new ActivityStatsDto(
            activeDays: \count($calendar),
            spanDays: $this->spanDays($calendar),
            busiestDayCount: $busiest?->watchCount ?? 0,
            busiestDate: $busiest?->date,
            longestStreakDays: $this->longestStreak($calendar),
            weekdays: $weekdays,
            calendar: $calendar,
        );
    }

    /**
     * @param ActivityDayDto[] $calendar ordered ascending
     */
    private function spanDays(array $calendar): int
    {
        if ([] === $calendar) {
            return 0;
        }

        $first = new \DateTimeImmutable($calendar[0]->date);
        $last = new \DateTimeImmutable($calendar[\count($calendar) - 1]->date);

        // Inclusive of both ends: a single-day history spans one day, not zero.
        return $first->diff($last)->days + 1;
    }

    /**
     * Longest run of consecutive calendar days with at least one watch.
     *
     * @param ActivityDayDto[] $calendar ordered ascending
     */
    private function longestStreak(array $calendar): int
    {
        $longest = 0;
        $current = 0;
        $previous = null;

        foreach ($calendar as $day) {
            $date = new \DateTimeImmutable($day->date);
            $current = (null !== $previous && 1 === (int) $previous->diff($date)->days) ? $current + 1 : 1;
            $longest = max($longest, $current);
            $previous = $date;
        }

        return $longest;
    }
}
