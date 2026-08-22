<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\StatsMath;
use PHPUnit\Framework\TestCase;

final class StatsMathTest extends TestCase
{
    public function testMeanOfEmptyArrayIsNull(): void
    {
        self::assertNull(StatsMath::mean([]));
    }

    public function testMean(): void
    {
        self::assertSame(3.0, StatsMath::mean([1.0, 3.0, 5.0]));
    }

    public function testMedianWithOddCount(): void
    {
        self::assertSame(3.0, StatsMath::median([5.0, 1.0, 3.0]));
    }

    public function testMedianWithEvenCount(): void
    {
        self::assertSame(2.5, StatsMath::median([1.0, 2.0, 3.0, 4.0]));
    }

    public function testStddevRequiresAtLeastTwoValues(): void
    {
        self::assertNull(StatsMath::stddev([4.0]));
    }

    public function testStddev(): void
    {
        // Sample stddev of [2, 4, 4, 4, 5, 5, 7, 9] is 2.138...
        self::assertEqualsWithDelta(2.138, StatsMath::stddev([2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0]), 0.001);
    }
}
