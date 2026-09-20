<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Card;

use App\Entity\Enum\CardRarity;
use App\Service\Card\RarityBands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the six tiers cut, and the two places that cut has to be the same.
 *
 * Worth its own file for the reason BadgeLadderTest is: the rule is written twice, once in
 * PHP and once as SQL, and everything visible about a card hangs off them agreeing. The
 * agreement itself needs Postgres to evaluate the CASE, so it is pinned in
 * CardCatalogueBuilderTest; what is pinned here is the arithmetic both sides do.
 */
final class RarityBandsTest extends TestCase
{
    public function testTheSixSharesAccountForTheWholeCatalogue(): void
    {
        $shares = RarityBands::shares();

        self::assertCount(6, $shares);
        self::assertEqualsWithDelta(1.0, array_sum($shares), 1e-9);
    }

    public function testTheSharesAreTheOnesTheOddsWerePublishedAgainst(): void
    {
        self::assertSame(
            [
                CardRarity::LEGENDARY->value => 0.006,
                CardRarity::ULTRA_RARE->value => 0.024,
                CardRarity::SUPER_RARE->value => 0.05,
                CardRarity::RARE->value => 0.12,
                CardRarity::UNCOMMON->value => 0.25,
                CardRarity::COMMON->value => 0.55,
            ],
            RarityBands::shares()
        );
    }

    /**
     * @return iterable<string, array{int, CardRarity}>
     */
    public static function positionsInAThousandCardCatalogue(): iterable
    {
        // Cutoffs at 1000 cards: 6, 30, 80, 200, 450, then Commune.
        yield 'the best card there is' => [1, CardRarity::LEGENDARY];
        yield 'the last Légendaire' => [6, CardRarity::LEGENDARY];
        yield 'one past it' => [7, CardRarity::ULTRA_RARE];
        yield 'the last Ultra Rare' => [30, CardRarity::ULTRA_RARE];
        yield 'one past that' => [31, CardRarity::SUPER_RARE];
        yield 'the last Super Rare' => [80, CardRarity::SUPER_RARE];
        yield 'the first Rare' => [81, CardRarity::RARE];
        yield 'the last Rare' => [200, CardRarity::RARE];
        yield 'the first Peu Commune' => [201, CardRarity::UNCOMMON];
        yield 'the last Peu Commune' => [450, CardRarity::UNCOMMON];
        yield 'the first Commune' => [451, CardRarity::COMMON];
        yield 'the very bottom' => [1000, CardRarity::COMMON];
    }

    #[DataProvider('positionsInAThousandCardCatalogue')]
    public function testTheTierAtEachBoundaryAndOneStepEitherSide(int $position, CardRarity $expected): void
    {
        self::assertSame($expected, RarityBands::rarityFor($position, 1000));
    }

    /**
     * The floors in the CASE exist so that a pack guaranteeing a tier always finds something
     * to draw from it. A band that rounded to zero cards would make the guarantee a lie.
     *
     * @return iterable<string, array{int}>
     */
    public static function catalogueSizes(): iterable
    {
        yield 'six cards, one per tier' => [6];
        yield 'a library barely past the gate' => [500];
        yield 'a realistic catalogue' => [4771];
        yield 'an implausibly large one' => [50000];
    }

    #[DataProvider('catalogueSizes')]
    public function testEveryTierHoldsAtLeastOneCard(int $total): void
    {
        $counts = [];
        for ($position = 1; $position <= $total; ++$position) {
            $rarity = RarityBands::rarityFor($position, $total);
            $counts[$rarity->value] = ($counts[$rarity->value] ?? 0) + 1;
        }

        foreach (CardRarity::cases() as $rarity) {
            self::assertArrayHasKey($rarity->value, $counts, sprintf('%s is empty at %d cards', $rarity->value, $total));
            self::assertGreaterThan(0, $counts[$rarity->value]);
        }
    }

    public function testTheBandsComeOutAtTheirTargetShares(): void
    {
        $total = 10000;
        $counts = [];
        for ($position = 1; $position <= $total; ++$position) {
            $rarity = RarityBands::rarityFor($position, $total);
            $counts[$rarity->value] = ($counts[$rarity->value] ?? 0) + 1;
        }

        foreach (RarityBands::shares() as $rarity => $share) {
            // Exact to the card: rank-and-cut is CEIL(N × share), not a sampling.
            self::assertEqualsWithDelta($share * $total, $counts[$rarity], 1, $rarity);
        }
    }

    /**
     * The reason the shares are integer parts per million rather than floats.
     *
     * 1000 × 0.03 is 30.000000000000004 in binary floating point and ceils to 31, while
     * Postgres' exact-decimal numeric makes it 30. One card would change band depending on
     * which language was asked. This is the case that caught it.
     */
    public function testACutoffThatLandsExactlyOnAWholeCardDoesNotRoundUp(): void
    {
        self::assertSame(30, RarityBands::cutoff(1000, 30000));
        self::assertSame(6, RarityBands::cutoff(1000, 6000));
        self::assertSame(80, RarityBands::cutoff(1000, 80000));

        self::assertSame(CardRarity::ULTRA_RARE, RarityBands::rarityFor(30, 1000));
        self::assertSame(CardRarity::SUPER_RARE, RarityBands::rarityFor(31, 1000));
    }

    public function testACutoffThatFallsBetweenCardsTakesTheCardAbove(): void
    {
        // 744 × 0.006 = 4.464, so five films are Légendaire and the sixth is not.
        self::assertSame(5, RarityBands::cutoff(744, 6000));
        self::assertSame(CardRarity::LEGENDARY, RarityBands::rarityFor(5, 744));
        self::assertSame(CardRarity::ULTRA_RARE, RarityBands::rarityFor(6, 744));
    }

    public function testTheLadderIsOrderedFromCommonUpwards(): void
    {
        self::assertSame(0, CardRarity::COMMON->rank());
        self::assertSame(5, CardRarity::LEGENDARY->rank());
        self::assertSame(CardRarity::SUPER_RARE, CardRarity::ULTRA_RARE->below());
        self::assertNull(CardRarity::COMMON->below());
    }

    public function testTheTopTwoTiersAreOutsideTheFreePool(): void
    {
        self::assertFalse(CardRarity::LEGENDARY->isInFreePool());
        self::assertFalse(CardRarity::ULTRA_RARE->isInFreePool());

        foreach ([CardRarity::COMMON, CardRarity::UNCOMMON, CardRarity::RARE, CardRarity::SUPER_RARE] as $rarity) {
            self::assertTrue($rarity->isInFreePool(), $rarity->value);
        }
    }

    public function testAGuaranteeOnlyEverOffersTheFloorAndBetter(): void
    {
        self::assertSame(
            [CardRarity::RARE, CardRarity::SUPER_RARE, CardRarity::ULTRA_RARE, CardRarity::LEGENDARY],
            CardRarity::RARE->andAbove()
        );
        self::assertSame([CardRarity::LEGENDARY], CardRarity::LEGENDARY->andAbove());
        self::assertCount(6, CardRarity::COMMON->andAbove());
    }
}
