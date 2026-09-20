<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;

/**
 * What comes out of a pack.
 *
 * **The odds are integers in basis points, out of 10 000.** Not decoration: a cumulative
 * walk over floats cannot be tested at its boundaries, because "the draw that lands exactly
 * on the edge of the Rare band" is not a float anybody can write down. With integers the
 * boundary is an integer, the test names it, and the odds provably sum to exactly one
 * rather than to 0.9999999999999999.
 *
 * **The free pack's top two tiers are zero here, and the draw excludes them again.** Two
 * guards for one rule, deliberately. A mistyped row in the table below must not be able to
 * leak a Légendaire out of a pack that costs nothing, and the second guard — an explicit
 * exclusion in CardPackOpener's SQL, built from CardRarity::isInFreePool() — is the one the
 * test asserts against. Belt and braces on the only rule whose failure would quietly undo
 * the whole economy.
 *
 * **Pity is hard, not a soft ramp.** Two counters, two thresholds, forced tiers. A rising
 * probability curve is neither testable at a point nor explicable to a player; "the next
 * card is guaranteed Super Rare" is both.
 */
final class PackOdds
{
    public const BASIS = 10000;

    /** Paid cards drawn since the last Super Rare before one is forced. */
    public const PITY_SUPER_RARE = 30;

    /** Paid cards drawn since the last Légendaire before one is forced. */
    public const PITY_LEGENDARY = 120;

    /**
     * The odds for an ordinary slot, per pack kind, in basis points.
     *
     * @return array<string, int>
     */
    public static function forKind(CardPackKind $kind): array
    {
        return match ($kind) {
            // 5 cards, unlimited, free. Almost all Commune, and no Ultra Rare or Légendaire
            // at any price — this is the pool that has to stay poor.
            CardPackKind::FREE => [
                CardRarity::COMMON->value => 7600,
                CardRarity::UNCOMMON->value => 1900,
                CardRarity::RARE->value => 420,
                CardRarity::SUPER_RARE->value => 80,
                CardRarity::ULTRA_RARE->value => 0,
                CardRarity::LEGENDARY->value => 0,
            ],
            CardPackKind::REEL => [
                CardRarity::COMMON->value => 4500,
                CardRarity::UNCOMMON->value => 2900,
                CardRarity::RARE->value => 1600,
                CardRarity::SUPER_RARE->value => 700,
                CardRarity::ULTRA_RARE->value => 250,
                CardRarity::LEGENDARY->value => 50,
            ],
            // No Commune at all: at 1 500 jetons the floor of the pack is the point of it.
            //
            // The top two rows are lower than they look like they should be, and that is a
            // correction rather than stinginess. Ultra Rare and Légendaire dominate what a
            // pack pays back in salvaged duplicates, and a first pass at 1500/500 made the
            // Coffret recycle at 28.7 % against the Bobine's 15.6 % — which would have made
            // it the arithmetically clever buy and the Bobine something nobody rational ever
            // opens. At 600/100 the two recycle within a point of each other, and the Coffret
            // earns its price the way it should: seven cards instead of five, no Commune at
            // all, a Super Rare floor, and a Légendaire chance three times the Bobine's.
            CardPackKind::BOXSET => [
                CardRarity::COMMON->value => 0,
                CardRarity::UNCOMMON->value => 3400,
                CardRarity::RARE->value => 4000,
                CardRarity::SUPER_RARE->value => 1900,
                CardRarity::ULTRA_RARE->value => 600,
                CardRarity::LEGENDARY->value => 100,
            ],
        };
    }

    /**
     * The odds for the guaranteed slot — the last card of every pack.
     *
     * Only ever tiers at or above the kind's floor, which is what makes a guarantee unable
     * to work against the player: it cannot hand out something worse than it promised, and
     * because it is its own roll it cannot cap something better either.
     *
     * @return array<string, int>
     */
    public static function forGuaranteedSlot(CardPackKind $kind): array
    {
        return match ($kind) {
            CardPackKind::FREE => [
                CardRarity::UNCOMMON->value => 8000,
                CardRarity::RARE->value => 1650,
                CardRarity::SUPER_RARE->value => 350,
                CardRarity::ULTRA_RARE->value => 0,
                CardRarity::LEGENDARY->value => 0,
            ],
            CardPackKind::REEL => [
                CardRarity::RARE->value => 7200,
                CardRarity::SUPER_RARE->value => 2000,
                CardRarity::ULTRA_RARE->value => 650,
                CardRarity::LEGENDARY->value => 150,
            ],
            CardPackKind::BOXSET => [
                CardRarity::SUPER_RARE->value => 7000,
                CardRarity::ULTRA_RARE->value => 2500,
                CardRarity::LEGENDARY->value => 500,
            ],
        };
    }

    /**
     * Picks a tier from a table of basis points.
     *
     * The randomness arrives as an already-drawn integer in [0, BASIS) rather than being
     * taken here, which is what lets the unit test land on every boundary exactly instead of
     * sampling and hoping. Walking in the ladder's own order — Commune upward — keeps the
     * mapping from draw to tier stable, so a test that pins "9 999 is the best card in the
     * table" stays true when a row is re-tuned.
     *
     * @param array<string, int> $odds
     */
    public static function roll(array $odds, int $draw): CardRarity
    {
        $cumulative = 0;
        $last = null;

        foreach (CardRarity::cases() as $rarity) {
            $weight = $odds[$rarity->value] ?? 0;
            if ($weight <= 0) {
                continue;
            }

            $last = $rarity;
            $cumulative += $weight;
            if ($draw < $cumulative) {
                return $rarity;
            }
        }

        if (null === $last) {
            throw new \LogicException('Une table de probabilités ne peut pas être vide.');
        }

        // Only reachable if a table sums to less than BASIS, which the unit test forbids.
        // Falling to the best tier present rather than throwing would silently reward a
        // typo, so this falls to the worst one that exists.
        return $last;
    }

    /**
     * The tier a pity counter forces, or null when neither has come due.
     *
     * Légendaire is checked first: a player who hits both counters on the same card has
     * waited 120 cards for the rarer promise, and paying the cheaper one would be the wrong
     * way round.
     */
    public static function pityFloor(int $sinceSuperRare, int $sinceLegendary): ?CardRarity
    {
        if ($sinceLegendary >= self::PITY_LEGENDARY) {
            return CardRarity::LEGENDARY;
        }

        if ($sinceSuperRare >= self::PITY_SUPER_RARE) {
            return CardRarity::SUPER_RARE;
        }

        return null;
    }

    /**
     * Narrows a table to a floor, renormalising nothing.
     *
     * Renormalising is exactly what must not happen: the remaining weights keep their
     * relative sizes, and roll() is given the narrowed sum as its range, so a forced
     * Super Rare floor still prefers Super Rare to Légendaire in the same proportion the
     * pack always did.
     *
     * @param array<string, int> $odds
     *
     * @return array<string, int>
     */
    public static function atOrAbove(array $odds, CardRarity $floor): array
    {
        $narrowed = [];
        foreach ($floor->andAbove() as $rarity) {
            $weight = $odds[$rarity->value] ?? 0;
            if ($weight > 0) {
                $narrowed[$rarity->value] = $weight;
            }
        }

        // A floor above everything the table offers — a pity Légendaire in a free pack that
        // has none. The floor still has to be honoured, so it becomes the whole table.
        if ([] === $narrowed) {
            $narrowed = [$floor->value => self::BASIS];
        }

        return $narrowed;
    }

    /**
     * @param array<string, int> $odds
     */
    public static function total(array $odds): int
    {
        return array_sum($odds);
    }
}
