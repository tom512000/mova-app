<?php

declare(strict_types=1);

namespace App\Service\Stats;

/**
 * Small pure-PHP statistics helpers applied to lightweight scalar arrays fetched
 * from the database (never full entities) — DQL has no portable MEDIAN/STDDEV,
 * and for a personal-scale dataset (thousands of rows) this is cheap either way.
 */
final class StatsMath
{
    /**
     * @param float[] $values
     */
    public static function mean(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }

        return array_sum($values) / \count($values);
    }

    /**
     * @param float[] $values
     */
    public static function median(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        if (0 === $count % 2) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * @param float[] $values
     */
    public static function stddev(array $values): ?float
    {
        $count = \count($values);
        if ($count < 2) {
            return null;
        }

        $mean = self::mean($values);
        $variance = array_sum(array_map(static fn (float $v) => ($v - $mean) ** 2, $values)) / ($count - 1);

        return sqrt($variance);
    }
}
