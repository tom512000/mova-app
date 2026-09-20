<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Card;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;
use App\Service\Card\JetonTariff;
use App\Service\Card\PackOdds;
use PHPUnit\Framework\TestCase;

/**
 * The economy, and the one property it exists to have.
 *
 * Free packs are unlimited. The design claim is that grinding them cannot buy a paying pack,
 * and that claim is arithmetic rather than a hope — so it is asserted here, from the same
 * numbers the game runs on. If someone raises the duplicate values or the daily cap without
 * thinking it through, this file is what says so.
 */
final class JetonTariffTest extends TestCase
{
    public function testTheTwoCommonestTiersPayAlmostNothing(): void
    {
        // 80 % of a free pack is these two. Paying properly for them would let the free
        // grind fund itself, which is the one thing the design cannot allow.
        self::assertSame(0, JetonTariff::duplicateValue(CardRarity::COMMON, 2));
        self::assertSame(1, JetonTariff::duplicateValue(CardRarity::UNCOMMON, 2));
    }

    public function testEveryTierIsWorthMoreThanTheOneBelowIt(): void
    {
        $previous = -1;
        foreach (CardRarity::cases() as $rarity) {
            $value = JetonTariff::duplicateValue($rarity, 2);
            self::assertGreaterThan($previous, $value, $rarity->value);
            $previous = $value;
        }
    }

    public function testTheSecondCopyPaysInFullAndLaterOnesPayLess(): void
    {
        self::assertSame(450, JetonTariff::duplicateValue(CardRarity::LEGENDARY, 2));
        self::assertSame(225, JetonTariff::duplicateValue(CardRarity::LEGENDARY, 3));
        self::assertSame(150, JetonTariff::duplicateValue(CardRarity::LEGENDARY, 4));
    }

    public function testDiminishingReturnsNeverFallBelowAQuarter(): void
    {
        $floor = (int) round(450 * JetonTariff::DIMINISHING_FLOOR_FRACTION);

        self::assertSame($floor, JetonTariff::duplicateValue(CardRarity::LEGENDARY, 50));
        self::assertSame($floor, JetonTariff::duplicateValue(CardRarity::LEGENDARY, 5000));
    }

    public function testTheConnectionGrantStartsAtAHundredAndCapsAtTwo(): void
    {
        self::assertSame(100, JetonTariff::dailyGrant(0));
        self::assertSame(110, JetonTariff::dailyGrant(1));
        self::assertSame(200, JetonTariff::dailyGrant(10));
        self::assertSame(200, JetonTariff::dailyGrant(365));
    }

    public function testTheFirstPayingPackCostsWhatItWasDesignedToCost(): void
    {
        self::assertSame(0, JetonTariff::priceOf(CardPackKind::FREE));
        self::assertSame(500, JetonTariff::priceOf(CardPackKind::REEL));
        self::assertSame(1500, JetonTariff::priceOf(CardPackKind::BOXSET));
    }

    /**
     * The load-bearing claim: no paying pack can be recycled into its own price.
     *
     * Computed against the real odds, assuming the worst case for the economy — every single
     * card a duplicate, every one paying in full. If a pack ever returned more than it cost,
     * jetons would stop being a sink and start being a loop.
     */
    public function testEveryPayingPackIsJetonNegativeEvenIfEveryCardIsADuplicate(): void
    {
        foreach ([CardPackKind::REEL, CardPackKind::BOXSET] as $kind) {
            $expected = $this->expectedSalvage($kind);
            $price = JetonTariff::priceOf($kind);

            self::assertLessThan(
                $price,
                $expected,
                sprintf('%s returns %.0f for %d', $kind->value, $expected, $price)
            );
            // And not marginally so: a recycle rate near 100 % would be a loop in practice.
            self::assertLessThan(0.40, $expected / $price, $kind->value.' recycle rate');
        }
    }

    /**
     * The two recycle rates are deliberately close, so neither pack is the clever buy and
     * the choice between them is about what you want rather than about arbitrage.
     *
     * This is the assertion that caught the first tuning of the Coffret: at 1500 basis points
     * of Ultra Rare and 500 of Légendaire it recycled at 28.7 % against the Bobine's 15.6 %,
     * which would have made buying anything else irrational. The top tiers dominate salvage,
     * so they are the rows to move when this fails.
     */
    public function testNoPayingPackIsArithmeticallyTheSmartBuy(): void
    {
        $reel = $this->expectedSalvage(CardPackKind::REEL) / JetonTariff::priceOf(CardPackKind::REEL);
        $boxset = $this->expectedSalvage(CardPackKind::BOXSET) / JetonTariff::priceOf(CardPackKind::BOXSET);

        self::assertEqualsWithDelta($reel, $boxset, 0.03);
    }

    /**
     * The Coffret has to be worth its price in what it *does*, since it is not worth it in
     * what it pays back: more cards, none of them Commune, and a real shot at the top.
     */
    public function testTheExpensivePackEarnsItsPriceInChanceRatherThanInSalvage(): void
    {
        $legendaryChance = static function (CardPackKind $kind): float {
            $miss = 1.0;
            $ordinary = PackOdds::forKind($kind)[CardRarity::LEGENDARY->value] ?? 0;
            for ($slot = 1; $slot < $kind->cardCount(); ++$slot) {
                $miss *= 1 - $ordinary / PackOdds::BASIS;
            }

            return 1 - $miss * (1 - (PackOdds::forGuaranteedSlot($kind)[CardRarity::LEGENDARY->value] ?? 0) / PackOdds::BASIS);
        };

        self::assertGreaterThan(
            2 * $legendaryChance(CardPackKind::REEL),
            $legendaryChance(CardPackKind::BOXSET)
        );
        self::assertSame(0, PackOdds::forKind(CardPackKind::BOXSET)[CardRarity::COMMON->value]);
    }

    /**
     * The whole "unlimited but poor" design, stated as a number.
     *
     * A saturated free pack — every card a duplicate — is worth this much, and the daily cap
     * is reached after a handful of them. Whatever that handful is, it must be small enough
     * that grinding is plainly not the way to earn, and the cap must be far below the price
     * of the cheapest paying pack.
     */
    public function testGrindingFreePacksAllDayCannotBuyThePayingPack(): void
    {
        $perPack = $this->expectedSalvage(CardPackKind::FREE);
        self::assertGreaterThan(0, $perPack);

        $packsToReachTheCap = JetonTariff::FREE_DUPLICATE_DAILY_CAP / $perPack;
        self::assertLessThan(30, $packsToReachTheCap, 'the cap should arrive within a few minutes of clicking');

        // A whole day of grinding, against the price of the cheapest paying pack.
        self::assertLessThan(
            JetonTariff::priceOf(CardPackKind::REEL),
            JetonTariff::FREE_DUPLICATE_DAILY_CAP,
            'a full day of grinding must not fund even one paying pack'
        );
    }

    public function testPlayingTheAppOutEarnsGrindingItSeveralTimesOver(): void
    {
        $grindOnly = JetonTariff::FREE_DUPLICATE_DAILY_CAP;
        $engaged = JetonTariff::DAILY_MAX
            + 8 * JetonTariff::DAILY_GAME_WIN
            + JetonTariff::DAILY_SWEEP_BONUS
            + JetonTariff::FREE_DUPLICATE_DAILY_CAP;

        self::assertGreaterThan(5 * $grindOnly, $engaged);
    }

    public function testASmallSetOfHardCardsBeatsALargeSetOfEasyOnes(): void
    {
        self::assertGreaterThan(
            JetonTariff::setBonus(20, 0),
            JetonTariff::setBonus(6, 4)
        );
    }

    public function testTheSetBonusIsCapped(): void
    {
        self::assertSame(JetonTariff::SET_BONUS_MAX, JetonTariff::setBonus(500, 500));
    }

    public function testFeatRungsPayMoreTheHigherTheyGo(): void
    {
        self::assertSame(0, JetonTariff::featReward(0));
        self::assertSame(50, JetonTariff::featReward(1));
        self::assertSame(100, JetonTariff::featReward(2));
        self::assertSame(200, JetonTariff::featReward(3));
        self::assertSame(400, JetonTariff::featReward(4));
        self::assertSame(400, JetonTariff::featReward(9));
    }

    /**
     * What a pack would pay back if every card in it were a duplicate paying in full: the
     * odds-weighted duplicate value of each slot, summed.
     */
    private function expectedSalvage(CardPackKind $kind): float
    {
        $values = JetonTariff::duplicateValues();

        $expectation = static function (array $odds) use ($values): float {
            $sum = 0.0;
            foreach ($odds as $rarity => $weight) {
                $sum += $weight / PackOdds::BASIS * $values[$rarity];
            }

            return $sum;
        };

        $ordinarySlots = $kind->cardCount() - 1;

        return $ordinarySlots * $expectation(PackOdds::forKind($kind))
            + $expectation(PackOdds::forGuaranteedSlot($kind));
    }
}
