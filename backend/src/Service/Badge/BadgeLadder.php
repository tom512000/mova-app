<?php

declare(strict_types=1);

namespace App\Service\Badge;

/**
 * How many works a badge level costs.
 *
 * The first level costs five works, and every level after it costs twice what the last one
 * did: 5, 10, 20, 40, 80, 160, 320. A flat five per level was the obvious rule and it does
 * not survive contact with a real library — 444 American films come out at level 88, which
 * is not a trophy, it is a counter with a ribbon on it. Doubling keeps the top of a seven
 * hundred work library at level 7 while still handing out the first badge at exactly five,
 * and it makes each level mean the same thing: you have seen as many again as it took to
 * get here.
 *
 * The rule is written twice, once in PHP and once as SQL, because the level has to be both
 * computed for display and grouped on inside the query that finds the date it was reached.
 * Both come off the constant below, which is the only number in it.
 */
final class BadgeLadder
{
    /** Works needed for the first level, and the amount every later level doubles from. */
    public const FIRST_LEVEL = 5;

    /**
     * The level a tally stands at. Zero means no badge yet.
     *
     * Counted by climbing the rungs rather than by floor(log2(n / 5)) + 1, which is the same
     * number and was measured to agree on every tally from 5 to 5000. The loop is used all
     * the same: it is exact by construction where the logarithm is only exact in practice,
     * and a libm that returned 1.9999999999999998 for log2(4) would silently hand out a
     * level 2 badge where a level 3 was earned. Eight iterations at the very worst.
     */
    public static function levelFor(int $workCount): int
    {
        $level = 0;
        for ($threshold = self::FIRST_LEVEL; $workCount >= $threshold; $threshold *= 2) {
            ++$level;
        }

        return $level;
    }

    /** What that level cost, and so what the next one will: worksForLevel($level + 1). */
    public static function worksForLevel(int $level): int
    {
        return $level < 1 ? 0 : self::FIRST_LEVEL * 2 ** ($level - 1);
    }

    /**
     * The row number that earned the level a tally currently stands at, in SQL.
     *
     * The only part of the ladder the query needs. The level itself is computed in PHP from
     * the same tally, so the rule above stays the one definition and this is the one thing
     * that cannot be done outside the statement: the date a badge was earned is the watch
     * date of the nth work, and only the query knows which row is the nth.
     *
     * LOG() here is Postgres' numeric one, which is exact decimal arithmetic rather than
     * the float logarithm the PHP side deliberately avoids — verified to agree with a
     * float-free reading on every tally from 5 to 5000.
     *
     * Interpolation is safe and deliberate: the only value reaching the string is the
     * integer constant above, and a column name chosen by this file's own callers.
     */
    public static function thresholdExpression(string $workCountColumn): string
    {
        return sprintf(
            '(%1$d * POWER(2, FLOOR(LOG(2, %2$s / %1$d.0))))::bigint',
            self::FIRST_LEVEL,
            $workCountColumn
        );
    }
}
