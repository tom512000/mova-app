<?php

declare(strict_types=1);

namespace App\Service\Trophy;

use App\DTO\Trophy\TrophyDto;
use App\Entity\Enum\Trophy;

/**
 * Every trophy, worked out from nothing but the days something was watched.
 *
 * Plain PHP over a list of dates rather than SQL, which is the opposite of what the badges
 * do, and deliberately. A badge is an aggregate over thousands of credits; a trophy is a
 * rule about a calendar — runs of consecutive days, Saturdays followed by Sundays, pauses,
 * anniversaries, Easter — and fourteen of those written as window functions would be
 * fourteen puzzles. The input is small enough not to matter: a diary holds a few hundred
 * distinct days, and twenty years of one would still be under eight thousand.
 *
 * It is also what makes the rules testable without a database, and every one of them is.
 *
 * Every rule produces the same two things: the figure it measures, and the day each rung was
 * reached, in order. The level is how many rungs were reached and the date is the last one.
 */
final class TrophyCalculator
{
    /**
     * @param list<string> $days ISO dates with at least one real viewing; duplicates and
     *                           order do not matter
     *
     * @return list<TrophyDto>
     */
    public function compute(array $days, \DateTimeImmutable $today): array
    {
        $days = array_values(array_unique($days));
        // ISO dates sort chronologically as plain strings.
        sort($days);
        $today = $this->date($today->format('Y-m-d'));

        $trophies = [];
        foreach (Trophy::cases() as $trophy) {
            [$value, $reachedOn] = $this->measure($trophy, $days, $today);

            $trophies[] = new TrophyDto(
                key: $trophy,
                family: $trophy->family(),
                tiers: $trophy->tiers(),
                value: $value,
                level: \count($reachedOn),
                earnedOn: [] === $reachedOn ? null : $reachedOn[\count($reachedOn) - 1],
            );
        }

        return $trophies;
    }

    /**
     * Easter Sunday of a Gregorian year, by the anonymous algorithm (Meeus, Jones, Butcher).
     *
     * Written out because nothing else can supply it: Postgres has no such function, and
     * PHP's easter_days() lives in ext-calendar, which the container does not ship.
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @param list<string> $days
     *
     * @return array{int, list<string>}
     */
    private function measure(Trophy $trophy, array $days, \DateTimeImmutable $today): array
    {
        $tiers = $trophy->tiers();

        return match ($trophy) {
            Trophy::GROUNDHOG_DAY => $this->longestRunOfDays($days, $tiers),
            Trophy::WEEKENDS => $this->reachedByCount($this->fullWeekends($days), $tiers),
            Trophy::DIRTY_DOZEN => $this->longestRunOfMonths($days, $tiers),
            Trophy::RETURN_OF_THE_JEDI => $this->longestPause($days, $tiers),
            Trophy::OLD_TIMERS => $this->yearsSinceTheFirst($days, $today, $tiers),
            Trophy::CHRISTMAS => $this->reachedByCount($this->onDates($days, ['12-24', '12-25']), $tiers),
            Trophy::NEW_YEAR => $this->reachedByCount($this->onDates($days, ['12-31', '01-01']), $tiers),
            Trophy::VALENTINE => $this->reachedByCount($this->onDates($days, ['02-14']), $tiers),
            Trophy::EASTER => $this->reachedByCount($this->onEaster($days), $tiers),
            Trophy::LABOUR_DAY => $this->reachedByCount($this->onDates($days, ['05-01']), $tiers),
            Trophy::BASTILLE_DAY => $this->reachedByCount($this->onDates($days, ['07-14']), $tiers),
            Trophy::HALLOWEEN => $this->reachedByCount($this->onDates($days, ['10-31']), $tiers),
            Trophy::FRIDAY_THE_13TH => $this->reachedByCount($this->onFridayThe13th($days), $tiers),
            Trophy::LEAP_DAY => $this->reachedByCount($this->onDates($days, ['02-29']), $tiers),
        };
    }

    /**
     * The longest run of consecutive days, and the day each rung was first reached.
     *
     * A run passes through every length on its way up, one day at a time, so the rung is
     * reached on the exact day the run is that long — in whichever run gets there first.
     *
     * @param list<string> $days
     * @param list<int>    $tiers
     *
     * @return array{int, list<string>}
     */
    private function longestRunOfDays(array $days, array $tiers): array
    {
        $longest = 0;
        $run = 0;
        $previous = null;
        $reachedOn = [];

        foreach ($days as $day) {
            $number = $this->dayNumber($day);
            $run = null !== $previous && $number === $previous + 1 ? $run + 1 : 1;
            $previous = $number;
            $longest = max($longest, $run);

            while (isset($tiers[\count($reachedOn)]) && $run >= $tiers[\count($reachedOn)]) {
                $reachedOn[] = $day;
            }
        }

        return [$longest, $reachedOn];
    }

    /**
     * The longest run of consecutive calendar months with something watched in each.
     *
     * The rung is dated to the first viewing of the month that completed the run: that is
     * the evening the twelfth month in a row stopped being an empty one.
     *
     * @param list<string> $days
     * @param list<int>    $tiers
     *
     * @return array{int, list<string>}
     */
    private function longestRunOfMonths(array $days, array $tiers): array
    {
        /** @var array<int, string> $firstDayOfMonth */
        $firstDayOfMonth = [];
        foreach ($days as $day) {
            // Months counted from year zero, so December and the January after it are
            // consecutive integers and a run crosses New Year without a special case.
            $month = (int) substr($day, 0, 4) * 12 + (int) substr($day, 5, 2);
            $firstDayOfMonth[$month] ??= $day;
        }

        $longest = 0;
        $run = 0;
        $previous = null;
        $reachedOn = [];

        foreach ($firstDayOfMonth as $month => $day) {
            $run = null !== $previous && $month === $previous + 1 ? $run + 1 : 1;
            $previous = $month;
            $longest = max($longest, $run);

            while (isset($tiers[\count($reachedOn)]) && $run >= $tiers[\count($reachedOn)]) {
                $reachedOn[] = $day;
            }
        }

        return [$longest, $reachedOn];
    }

    /**
     * The longest stretch of days with nothing watched, between two days that had something.
     *
     * Counted in days *without* a film: the 1st and the 31st of a month are thirty days apart
     * and have twenty-nine empty days between them. The rung is dated to the return — the
     * trophy is for coming back, not for leaving. A stretch still under way today does not
     * count, since nobody has come back from it yet.
     *
     * @param list<string> $days
     * @param list<int>    $tiers
     *
     * @return array{int, list<string>}
     */
    private function longestPause(array $days, array $tiers): array
    {
        $longest = 0;
        $previous = null;
        $reachedOn = [];

        foreach ($days as $day) {
            $number = $this->dayNumber($day);

            if (null !== $previous) {
                $pause = $number - $previous - 1;
                $longest = max($longest, $pause);

                while (isset($tiers[\count($reachedOn)]) && $pause >= $tiers[\count($reachedOn)]) {
                    $reachedOn[] = $day;
                }
            }

            $previous = $number;
        }

        return [$longest, $reachedOn];
    }

    /**
     * Whole years since the first viewing in the diary — not since the account was created.
     *
     * The account date is not a fact about anybody's film habits: this app's first account
     * was created by a migration, long after the diary it holds began, and every trophy
     * counted from it would have started from zero on the day it was deployed.
     *
     * @param list<string> $days
     * @param list<int>    $tiers
     *
     * @return array{int, list<string>}
     */
    private function yearsSinceTheFirst(array $days, \DateTimeImmutable $today, array $tiers): array
    {
        if ([] === $days) {
            return [0, []];
        }

        $first = $this->date($days[0]);
        $years = $first > $today ? 0 : $first->diff($today)->y;

        $reachedOn = [];
        foreach ($tiers as $tier) {
            $anniversary = $first->modify("+{$tier} years");
            if ($anniversary > $today) {
                break;
            }
            $reachedOn[] = $anniversary->format('Y-m-d');
        }

        return [$years, $reachedOn];
    }

    /**
     * The Sundays of every weekend with something watched on both days, in order.
     *
     * Dated to the Sunday because that is when the weekend became a full one.
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    private function fullWeekends(array $days): array
    {
        $watched = array_flip($days);
        $sundays = [];

        foreach ($days as $day) {
            $date = $this->date($day);
            if ('6' !== $date->format('N')) {
                continue;
            }

            $sunday = $date->modify('+1 day')->format('Y-m-d');
            if (isset($watched[$sunday])) {
                $sundays[] = $sunday;
            }
        }

        return $sundays;
    }

    /**
     * @param list<string> $days
     * @param list<string> $monthDays "MM-DD", the same every year
     *
     * @return list<string>
     */
    private function onDates(array $days, array $monthDays): array
    {
        return array_values(array_filter(
            $days,
            static fn (string $day) => \in_array(substr($day, 5), $monthDays, true)
        ));
    }

    /**
     * Easter moves, so the date is worked out once per year the diary covers.
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    private function onEaster(array $days): array
    {
        /** @var array<int, string> $easters */
        $easters = [];

        return array_values(array_filter($days, static function (string $day) use (&$easters): bool {
            $year = (int) substr($day, 0, 4);
            $easters[$year] ??= self::easterSunday($year);

            return $day === $easters[$year];
        }));
    }

    /**
     * @param list<string> $days
     *
     * @return list<string>
     */
    private function onFridayThe13th(array $days): array
    {
        return array_values(array_filter(
            $days,
            fn (string $day) => '13' === substr($day, 8, 2) && '5' === $this->date($day)->format('N')
        ));
    }

    /**
     * For the trophies that simply count occasions: the nth rung is reached on the nth one.
     *
     * @param list<string> $occasions in order
     * @param list<int>    $tiers
     *
     * @return array{int, list<string>}
     */
    private function reachedByCount(array $occasions, array $tiers): array
    {
        $reachedOn = [];
        foreach ($tiers as $tier) {
            if (!isset($occasions[$tier - 1])) {
                break;
            }
            $reachedOn[] = $occasions[$tier - 1];
        }

        return [\count($occasions), $reachedOn];
    }

    /**
     * A calendar day as a plain integer, so "the next day" is "plus one".
     *
     * Read at midnight UTC: a local timezone would put a daylight-saving change inside some
     * of these days, make them twenty-three hours long, and quietly break a run in two.
     */
    private function dayNumber(string $day): int
    {
        return intdiv($this->date($day)->getTimestamp(), 86400);
    }

    private function date(string $day): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an ISO calendar day.', $day));
        }

        return $date;
    }
}
