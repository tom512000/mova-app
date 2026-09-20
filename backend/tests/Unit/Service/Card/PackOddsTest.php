<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Card;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;
use App\Service\Card\PackOdds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a pack is allowed to contain.
 *
 * The file exists for one assertion above all the others: a free pack must never yield an
 * Ultra Rare or a Légendaire. Free packs are unlimited and cost nothing, so that exclusion
 * is the only thing standing between "generous" and "the collection completes itself in an
 * afternoon". Everything else here is the arithmetic that keeps the published odds true.
 *
 * The odds are basis points precisely so these boundaries can be named. "The draw that lands
 * exactly on the edge of the Rare band" is an integer, and a test can write it down.
 */
final class PackOddsTest extends TestCase
{
    /**
     * @return iterable<string, array{CardPackKind}>
     */
    public static function kinds(): iterable
    {
        foreach (CardPackKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function testEveryOddsTableSumsToExactlyOne(CardPackKind $kind): void
    {
        self::assertSame(PackOdds::BASIS, PackOdds::total(PackOdds::forKind($kind)));
        self::assertSame(PackOdds::BASIS, PackOdds::total(PackOdds::forGuaranteedSlot($kind)));
    }

    public function testAFreePackOffersNoUltraRareAndNoLegendaryInAnySlot(): void
    {
        foreach ([PackOdds::forKind(CardPackKind::FREE), PackOdds::forGuaranteedSlot(CardPackKind::FREE)] as $odds) {
            self::assertSame(0, $odds[CardRarity::ULTRA_RARE->value] ?? 0);
            self::assertSame(0, $odds[CardRarity::LEGENDARY->value] ?? 0);
        }
    }

    /**
     * Sweeping every possible draw is cheap at ten thousand and proves the exclusion rather
     * than sampling it: there is no integer in the range that reaches the top two tiers.
     */
    public function testNoDrawWhatsoeverReachesTheTopTwoTiersInAFreePack(): void
    {
        foreach ([PackOdds::forKind(CardPackKind::FREE), PackOdds::forGuaranteedSlot(CardPackKind::FREE)] as $odds) {
            for ($draw = 0; $draw < PackOdds::BASIS; ++$draw) {
                $rarity = PackOdds::roll($odds, $draw);
                self::assertLessThan(
                    CardRarity::ULTRA_RARE->rank(),
                    $rarity->rank(),
                    sprintf('draw %d produced %s', $draw, $rarity->value)
                );
            }
        }
    }

    /**
     * The Bobine's ordinary slot: 4500 Commune, 2900 Peu Commune, 1600 Rare, 700 Super Rare,
     * 250 Ultra Rare, 50 Légendaire. Each boundary, and the draw either side of it.
     *
     * @return iterable<string, array{int, CardRarity}>
     */
    public static function reelDraws(): iterable
    {
        yield 'the very first draw' => [0, CardRarity::COMMON];
        yield 'the last Commune' => [4499, CardRarity::COMMON];
        yield 'the first Peu Commune' => [4500, CardRarity::UNCOMMON];
        yield 'the last Peu Commune' => [7399, CardRarity::UNCOMMON];
        yield 'the first Rare' => [7400, CardRarity::RARE];
        yield 'the last Rare' => [8999, CardRarity::RARE];
        yield 'the first Super Rare' => [9000, CardRarity::SUPER_RARE];
        yield 'the last Super Rare' => [9699, CardRarity::SUPER_RARE];
        yield 'the first Ultra Rare' => [9700, CardRarity::ULTRA_RARE];
        yield 'the last Ultra Rare' => [9949, CardRarity::ULTRA_RARE];
        yield 'the first Légendaire' => [9950, CardRarity::LEGENDARY];
        yield 'the very last draw' => [9999, CardRarity::LEGENDARY];
    }

    #[DataProvider('reelDraws')]
    public function testTheTierAtEachCumulativeBoundary(int $draw, CardRarity $expected): void
    {
        self::assertSame($expected, PackOdds::roll(PackOdds::forKind(CardPackKind::REEL), $draw));
    }

    #[DataProvider('kinds')]
    public function testTheGuaranteedSlotNeverOffersAnythingBelowTheKindsFloor(CardPackKind $kind): void
    {
        $floor = $kind->guaranteedFloor();

        foreach (PackOdds::forGuaranteedSlot($kind) as $rarity => $weight) {
            if ($weight > 0) {
                self::assertGreaterThanOrEqual(
                    $floor->rank(),
                    CardRarity::from($rarity)->rank(),
                    sprintf('%s offers %s below its %s floor', $kind->value, $rarity, $floor->value)
                );
            }
        }
    }

    public function testNarrowingToAFloorKeepsTheRemainingWeightsInProportion(): void
    {
        $narrowed = PackOdds::atOrAbove(PackOdds::forKind(CardPackKind::REEL), CardRarity::SUPER_RARE);

        // 700 : 250 : 50 — unchanged, not renormalised. A forced Super Rare should still
        // prefer Super Rare to Légendaire exactly as much as the pack always did.
        self::assertSame(
            [
                CardRarity::SUPER_RARE->value => 700,
                CardRarity::ULTRA_RARE->value => 250,
                CardRarity::LEGENDARY->value => 50,
            ],
            $narrowed
        );
        self::assertSame(1000, PackOdds::total($narrowed));
    }

    public function testAFloorAboveEverythingTheTableOffersStillHonoursTheFloor(): void
    {
        // Pity owing a Légendaire against a table that has none — the free pack's. The floor
        // wins: a promise made is a promise kept, whatever the table says.
        $narrowed = PackOdds::atOrAbove(PackOdds::forKind(CardPackKind::FREE), CardRarity::LEGENDARY);

        self::assertSame(CardRarity::LEGENDARY, PackOdds::roll($narrowed, 0));
        self::assertSame(CardRarity::LEGENDARY, PackOdds::roll($narrowed, PackOdds::total($narrowed) - 1));
    }

    public function testHardPityFiresOnItsExactCountAndNotOneCardEarlier(): void
    {
        self::assertNull(PackOdds::pityFloor(PackOdds::PITY_SUPER_RARE - 1, 0));
        self::assertSame(CardRarity::SUPER_RARE, PackOdds::pityFloor(PackOdds::PITY_SUPER_RARE, 0));

        self::assertNull(PackOdds::pityFloor(0, PackOdds::PITY_LEGENDARY - 1));
        self::assertSame(CardRarity::LEGENDARY, PackOdds::pityFloor(0, PackOdds::PITY_LEGENDARY));
    }

    public function testWhenBothCountersComeDueAtOnceTheRarerPromiseIsPaid(): void
    {
        self::assertSame(
            CardRarity::LEGENDARY,
            PackOdds::pityFloor(PackOdds::PITY_SUPER_RARE, PackOdds::PITY_LEGENDARY)
        );
    }

    public function testOnlyPayingPacksAdvancePity(): void
    {
        self::assertFalse(CardPackKind::FREE->buildsPity());
        self::assertTrue(CardPackKind::REEL->buildsPity());
        self::assertTrue(CardPackKind::BOXSET->buildsPity());
    }

    public function testTheFreePackIsTheOnlyOneThatCostsNothing(): void
    {
        self::assertTrue(CardPackKind::FREE->isFree());
        self::assertFalse(CardPackKind::REEL->isFree());
        self::assertFalse(CardPackKind::BOXSET->isFree());
    }

    public function testAPackDealsFiveCardsUnlessItIsTheBigOne(): void
    {
        self::assertSame(5, CardPackKind::FREE->cardCount());
        self::assertSame(5, CardPackKind::REEL->cardCount());
        self::assertSame(7, CardPackKind::BOXSET->cardCount());
    }

    public function testAnEmptyTableIsARefusalRatherThanASilentCommune(): void
    {
        $this->expectException(\LogicException::class);

        PackOdds::roll([], 0);
    }
}
