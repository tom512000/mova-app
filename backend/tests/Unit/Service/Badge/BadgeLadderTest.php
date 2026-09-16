<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Badge;

use App\Service\Badge\BadgeLadder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rungs, and the two places they have to line up.
 *
 * Worth its own file because everything visible about a badge hangs off these two numbers:
 * the figure printed on the medallion, the "plus quatre pour le niveau suivant" under it,
 * and — through the threshold — which watch date the badge claims it was earned on. An
 * off-by-one here is not a wrong pixel, it is a badge dated to the wrong evening.
 */
final class BadgeLadderTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function tallies(): iterable
    {
        yield 'nothing watched' => [0, 0];
        yield 'one short of the first rung' => [4, 0];
        yield 'the first rung, exactly' => [5, 1];
        yield 'one short of the second' => [9, 1];
        yield 'the second rung' => [10, 2];
        yield 'the third' => [20, 3];
        yield 'between the third and the fourth' => [39, 3];
        yield 'the fourth' => [40, 4];
        yield 'the fifth' => [80, 5];
        yield 'the sixth' => [160, 6];
        yield 'the seventh' => [320, 7];
        // The top of a real seven hundred work library, and the reason the ladder doubles:
        // a flat five per level put this same tally at 88.
        yield 'every American film in a real library' => [444, 7];
        yield 'the eighth' => [640, 8];
    }

    #[DataProvider('tallies')]
    public function testTheLevelATallyStandsAt(int $workCount, int $expected): void
    {
        self::assertSame($expected, BadgeLadder::levelFor($workCount));
    }

    public function testEachLevelCostsTwiceTheLast(): void
    {
        self::assertSame(0, BadgeLadder::worksForLevel(0));
        self::assertSame([5, 10, 20, 40, 80, 160, 320], array_map(
            BadgeLadder::worksForLevel(...),
            [1, 2, 3, 4, 5, 6, 7]
        ));
    }

    /**
     * The two halves have to be each other's inverse, or the badge would announce a level it
     * has not reached: the count that buys a level must land on exactly that level, and one
     * work less must land one rung below.
     */
    public function testTheRungsAndTheLevelsAgreeAllTheWayUp(): void
    {
        for ($level = 1; $level <= 10; ++$level) {
            $cost = BadgeLadder::worksForLevel($level);

            self::assertSame($level, BadgeLadder::levelFor($cost), "level {$level} at its own cost");
            self::assertSame($level - 1, BadgeLadder::levelFor($cost - 1), "level {$level} one work short");
        }
    }

    /**
     * The figure the card prints under the medallion. It is never zero or negative: a badge
     * always has a next level to reach, however far off.
     */
    public function testWhatIsLeftToReachTheNextLevel(): void
    {
        foreach ([5 => 5, 9 => 1, 10 => 10, 20 => 20, 444 => 196] as $workCount => $expected) {
            $level = BadgeLadder::levelFor($workCount);

            self::assertSame($expected, BadgeLadder::worksForLevel($level + 1) - $workCount, "from {$workCount}");
        }
    }
}
