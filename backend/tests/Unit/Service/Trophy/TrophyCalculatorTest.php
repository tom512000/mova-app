<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trophy;

use App\DTO\Trophy\TrophyDto;
use App\Entity\Enum\Trophy;
use App\Entity\Enum\TrophyFamily;
use App\Service\Trophy\TrophyCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every trophy is a rule about a calendar, and calendars are where off-by-ones live: a run
 * that should be seven days and is six, a pause measured between dates instead of across
 * the days in between, a Sunday dated to its Saturday. None of that shows on the shelf —
 * the trophy is simply there or not — so each rule is pinned here, on dates checked by hand.
 */
final class TrophyCalculatorTest extends TestCase
{
    private const TODAY = '2026-09-17';

    private TrophyCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TrophyCalculator();
    }

    public function testAnEmptyDiaryListsEveryTrophyLocked(): void
    {
        $trophies = $this->calculator->compute([], new \DateTimeImmutable(self::TODAY));

        // Locked ones come back too: fourteen things to go and get, not an empty shelf.
        self::assertCount(\count(Trophy::cases()), $trophies);
        foreach ($trophies as $trophy) {
            self::assertSame(0, $trophy->level, $trophy->key->value);
            self::assertNull($trophy->earnedOn, $trophy->key->value);
        }
    }

    public function testTheCatalogueKeepsItsOrderAndItsShelves(): void
    {
        $trophies = $this->calculator->compute([], new \DateTimeImmutable(self::TODAY));

        self::assertSame(Trophy::cases(), array_map(static fn (TrophyDto $trophy) => $trophy->key, $trophies));
        self::assertSame(TrophyFamily::REGULARITY, $trophies[0]->family);
        self::assertSame(TrophyFamily::SPECIAL_DATES, $trophies[\count($trophies) - 1]->family);
    }

    public function testARunIsDatedToTheDayItReachedEachRung(): void
    {
        $trophy = $this->trophy(Trophy::GROUNDHOG_DAY, $this->span('2026-01-01', 8));

        self::assertSame(8, $trophy->value);
        // 3 and 7, not 14: the rungs are 3 · 7 · 14 · 30 · 60 · 100.
        self::assertSame(2, $trophy->level);
        self::assertSame('2026-01-07', $trophy->earnedOn, 'the seventh day in a row, not the last day of the run');
    }

    public function testABreakRestartsTheRunAndALongerLaterRunStillCounts(): void
    {
        $days = [
            ...$this->span('2026-01-01', 4),
            // 5 January is missing: the run stops at four.
            ...$this->span('2026-01-06', 7),
        ];

        $trophy = $this->trophy(Trophy::GROUNDHOG_DAY, $days);

        self::assertSame(7, $trophy->value);
        self::assertSame(2, $trophy->level);
        self::assertSame('2026-01-12', $trophy->earnedOn);
    }

    public function testARunCrossesTheEndOfAMonthAndOfAYear(): void
    {
        $trophy = $this->trophy(Trophy::GROUNDHOG_DAY, $this->span('2025-12-29', 7));

        self::assertSame(7, $trophy->value, '29 December to 4 January is one run');
    }

    public function testAWeekendNeedsBothItsDaysAndIsDatedToTheSunday(): void
    {
        $days = [
            '2026-01-03', '2026-01-04', // Saturday and Sunday: a full weekend
            '2026-01-10',               // Saturday only
            '2026-01-18',               // Sunday only
        ];

        $trophy = $this->trophy(Trophy::WEEKENDS, $days);

        self::assertSame(1, $trophy->value);
        self::assertSame(0, $trophy->level, 'the first rung is five weekends');
    }

    public function testTheFifthFullWeekendWinsTheFirstRung(): void
    {
        $days = [];
        foreach (['2026-01-03', '2026-01-10', '2026-01-17', '2026-01-24', '2026-01-31'] as $saturday) {
            $days[] = $saturday;
            $days[] = (new \DateTimeImmutable($saturday))->modify('+1 day')->format('Y-m-d');
        }

        $trophy = $this->trophy(Trophy::WEEKENDS, $days);

        self::assertSame(5, $trophy->value);
        self::assertSame(1, $trophy->level);
        self::assertSame('2026-02-01', $trophy->earnedOn);
    }

    public function testTwelveMonthsInARowAcrossNewYear(): void
    {
        $days = [];
        foreach (['2025-06', '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
            $days[] = "{$month}-20";
        }
        // The twelfth month, watched twice: the rung is dated to its first viewing.
        $days[] = '2026-05-09';
        $days[] = '2026-05-02';

        $trophy = $this->trophy(Trophy::DIRTY_DOZEN, $days);

        self::assertSame(12, $trophy->value);
        self::assertSame(1, $trophy->level);
        self::assertSame('2026-05-02', $trophy->earnedOn);
    }

    public function testAnEmptyMonthBreaksTheRun(): void
    {
        $days = [];
        foreach (['2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06', /* no July */ '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01'] as $month) {
            $days[] = "{$month}-15";
        }

        $trophy = $this->trophy(Trophy::DIRTY_DOZEN, $days);

        self::assertSame(6, $trophy->value);
        self::assertSame(0, $trophy->level);
    }

    public function testAPauseIsCountedInEmptyDaysAndDatedToTheReturn(): void
    {
        // 1 and 31 January: thirty days apart, twenty-nine of them without a film. Not enough.
        $short = $this->trophy(Trophy::RETURN_OF_THE_JEDI, ['2026-01-01', '2026-01-31']);
        self::assertSame(29, $short->value);
        self::assertSame(0, $short->level);

        // 1 January and 1 February: thirty empty days, and the trophy goes to the comeback.
        $long = $this->trophy(Trophy::RETURN_OF_THE_JEDI, ['2026-01-01', '2026-02-01']);
        self::assertSame(30, $long->value);
        self::assertSame(1, $long->level);
        self::assertSame('2026-02-01', $long->earnedOn);
    }

    public function testAPauseStillUnderWayIsNotAReturn(): void
    {
        // Nothing since January, and it is September: a long absence, but nobody came back.
        $trophy = $this->trophy(Trophy::RETURN_OF_THE_JEDI, ['2026-01-01']);

        self::assertSame(0, $trophy->level);
    }

    public function testYearsAreCountedFromTheFirstViewingToToday(): void
    {
        $trophy = $this->trophy(Trophy::OLD_TIMERS, ['2025-04-14', '2026-06-01']);

        self::assertSame(1, $trophy->value);
        self::assertSame(1, $trophy->level);
        self::assertSame('2026-04-14', $trophy->earnedOn, 'the anniversary, not a viewing');
    }

    public function testNoYearBeforeTheFirstAnniversary(): void
    {
        $trophy = $this->trophy(Trophy::OLD_TIMERS, ['2025-09-18']);

        self::assertSame(0, $trophy->value, 'one day short of a year');
        self::assertSame(0, $trophy->level);
    }

    /**
     * @return iterable<string, array{Trophy, string, string}>
     */
    public static function fixedDates(): iterable
    {
        yield 'Christmas Eve' => [Trophy::CHRISTMAS, '2025-12-24', '2025-12-26'];
        yield 'Christmas Day' => [Trophy::CHRISTMAS, '2025-12-25', '2025-12-23'];
        yield "New Year's Eve" => [Trophy::NEW_YEAR, '2025-12-31', '2025-12-30'];
        yield "New Year's Day" => [Trophy::NEW_YEAR, '2026-01-01', '2026-01-02'];
        yield 'Valentine' => [Trophy::VALENTINE, '2026-02-14', '2026-02-15'];
        yield 'Labour Day' => [Trophy::LABOUR_DAY, '2025-05-01', '2025-04-30'];
        yield 'Bastille Day' => [Trophy::BASTILLE_DAY, '2025-07-14', '2025-07-13'];
        yield 'Halloween' => [Trophy::HALLOWEEN, '2025-10-31', '2025-11-01'];
        yield 'Leap day' => [Trophy::LEAP_DAY, '2024-02-29', '2024-03-01'];
    }

    #[DataProvider('fixedDates')]
    public function testAFixedDateAndTheDayNextToIt(Trophy $key, string $onTheDay, string $nextToIt): void
    {
        $won = $this->trophy($key, [$nextToIt, $onTheDay]);
        self::assertSame(1, $won->level);
        self::assertSame($onTheDay, $won->earnedOn);

        self::assertSame(0, $this->trophy($key, [$nextToIt])->level, 'a day off is not the day');
    }

    public function testTheSameDateEveryYearIsWonOnceAndDatedToTheFirst(): void
    {
        $trophy = $this->trophy(Trophy::HALLOWEEN, ['2026-10-31', '2025-10-31']);

        self::assertSame(2, $trophy->value, 'counted every year');
        self::assertSame('2025-10-31', $trophy->earnedOn, 'won the first time');
    }

    /**
     * @return iterable<int, array{int, string}>
     */
    public static function easterSundays(): iterable
    {
        // Checked against published calendars, since the container has no ext-calendar to
        // check against — including 2024, when Easter fell in March.
        yield [2019, '2019-04-21'];
        yield [2024, '2024-03-31'];
        yield [2025, '2025-04-20'];
        yield [2026, '2026-04-05'];
        yield [2027, '2027-03-28'];
    }

    #[DataProvider('easterSundays')]
    public function testEasterSunday(int $year, string $expected): void
    {
        self::assertSame($expected, TrophyCalculator::easterSunday($year));
    }

    public function testEasterIsTheSundayOfThatYearAndNotAFixedDate(): void
    {
        // 20 April was Easter in 2025 and is not in 2026.
        self::assertSame(1, $this->trophy(Trophy::EASTER, ['2025-04-20'])->level);
        self::assertSame(0, $this->trophy(Trophy::EASTER, ['2026-04-20'])->level);
    }

    public function testFridayThe13thCountsDaysNotFilms(): void
    {
        $days = [
            '2025-06-13', // Friday
            '2025-06-13', // the same Friday, another film: still one day
            '2026-02-13', // Friday
            '2026-01-13', // Tuesday: not one
        ];

        $trophy = $this->trophy(Trophy::FRIDAY_THE_13TH, $days);

        self::assertSame(2, $trophy->value);
        self::assertSame(1, $trophy->level, 'the second rung is five Fridays the 13th');
        self::assertSame('2025-06-13', $trophy->earnedOn);
    }

    public function testTheOrderTheDaysArriveInDoesNotMatter(): void
    {
        $days = $this->span('2026-01-01', 7);

        self::assertEquals(
            $this->trophy(Trophy::GROUNDHOG_DAY, $days),
            $this->trophy(Trophy::GROUNDHOG_DAY, array_reverse($days)),
        );
    }

    /**
     * @param list<string> $days
     */
    private function trophy(Trophy $key, array $days): TrophyDto
    {
        foreach ($this->calculator->compute($days, new \DateTimeImmutable(self::TODAY)) as $trophy) {
            if ($key === $trophy->key) {
                return $trophy;
            }
        }

        self::fail("no {$key->value} trophy");
    }

    /**
     * $count consecutive days, starting on $from.
     *
     * @return list<string>
     */
    private function span(string $from, int $count): array
    {
        $start = new \DateTimeImmutable($from);

        return array_map(
            static fn (int $offset) => $start->modify("+{$offset} days")->format('Y-m-d'),
            range(0, $count - 1)
        );
    }
}
