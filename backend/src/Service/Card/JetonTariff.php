<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;

/**
 * Every number in the economy, in one file.
 *
 * The whole design has to answer one question: free packs are unlimited and cost nothing, so
 * what stops somebody opening a thousand of them and buying their way to the top? The answer
 * is not a cooldown — the packs really are unlimited — it is three rules that work together,
 * and none of them survives alone:
 *
 *  1. Ultra Rare and Légendaire are absent from the free pool entirely (CardRarity).
 *  2. Free packs never advance the pity counters (CardPackKind).
 *  3. Jetons salvaged from free-pack duplicates stop at FREE_DUPLICATE_DAILY_CAP per day.
 *
 * The arithmetic that follows from them, against a realistic 4 771-card catalogue:
 *
 *  | source                        | jetons/day |
 *  |-------------------------------|------------|
 *  | grinding free packs alone     |        100 |
 *  | + the connection grant        |        300 |
 *  | + all eight daily boards      |        600 |
 *
 * A saturated free pack yields about 8 jetons, so the cap lands after roughly a dozen of
 * them — a minute or two. **No amount of clicking moves the 100.** Playing the app for one
 * day earns six times what a whole day of grinding does, which makes grinding-to-buy
 * strictly dominated by simply opening the free packs you were going to open anyway. That is
 * the behaviour the design wants, and it is arithmetic rather than a hope.
 *
 * Every paid pack is also jeton-negative: a Bobine costing 500 returns about 78 if every
 * card in it were a duplicate, and a Coffret costing 1 500 returns about 290. You can never
 * recycle a pack into its own price, so jetons are a sink and not a loop, and the two
 * recycle rates are deliberately close so that neither pack is the arithmetically clever buy.
 */
final class JetonTariff
{
    // ------------------------------------------------------------------ packs

    public const PRICE_FREE = 0;

    /** The first paying pack. */
    public const PRICE_REEL = 500;

    public const PRICE_BOXSET = 1500;

    public static function priceOf(CardPackKind $kind): int
    {
        return match ($kind) {
            CardPackKind::FREE => self::PRICE_FREE,
            CardPackKind::REEL => self::PRICE_REEL,
            CardPackKind::BOXSET => self::PRICE_BOXSET,
        };
    }

    // --------------------------------------------------------- daily grant

    /** What simply showing up is worth. */
    public const DAILY_BASE = 100;

    /** Added per consecutive day already on the streak… */
    public const DAILY_STREAK_STEP = 10;

    /** …up to here, so a long streak is worth exactly twice a first day and no more. */
    public const DAILY_MAX = 200;

    /**
     * What the grant pays, given the streak *before* today is counted.
     *
     * The same arithmetic runs inside the conditional UPDATE that applies it — it has to,
     * because the amount depends on the streak that statement is updating — so this is the
     * PHP half of a rule written twice, and the test pins them together.
     */
    public static function dailyGrant(int $streakBefore): int
    {
        return min(self::DAILY_MAX, self::DAILY_BASE + self::DAILY_STREAK_STEP * max(0, $streakBefore));
    }

    // ----------------------------------------------------------- duplicates

    /**
     * What a duplicate is worth, before diminishing returns.
     *
     * Commune pays nothing and Peu Commune pays one, which is not stinginess but the first
     * of the three rules above doing its work: those two tiers are 80 % of a free pack, so
     * paying properly for them would make the free grind fund itself.
     *
     * @return array<string, int>
     */
    public static function duplicateValues(): array
    {
        return [
            CardRarity::COMMON->value => 0,
            CardRarity::UNCOMMON->value => 1,
            CardRarity::RARE->value => 12,
            CardRarity::SUPER_RARE->value => 45,
            CardRarity::ULTRA_RARE->value => 140,
            CardRarity::LEGENDARY->value => 450,
        ];
    }

    /** Below this, a duplicate never loses value — it has none to lose. */
    public const DIMINISHING_FLOOR_FRACTION = 0.25;

    /**
     * What this particular duplicate pays.
     *
     * $copies is the count *after* the pull, so the second copy of a card is $copies = 2 and
     * pays in full. The third pays half, the fourth a third, and it never falls below a
     * quarter — a player who keeps pulling the same Légendaire should keep getting something
     * for it, just not the same something.
     */
    public static function duplicateValue(CardRarity $rarity, int $copies): int
    {
        $base = self::duplicateValues()[$rarity->value];
        if ($copies <= 2) {
            return $base;
        }

        return (int) round($base * max(self::DIMINISHING_FLOOR_FRACTION, 1 / ($copies - 1)));
    }

    /**
     * The hard daily ceiling on jetons salvaged from free packs.
     *
     * This is the number the whole "unlimited but poor" design rests on. Free packs keep
     * dealing cards forever once it is reached; what stops is the money.
     */
    public const FREE_DUPLICATE_DAILY_CAP = 100;

    // -------------------------------------------------------------- playing

    /** A daily board won. Eight games, so 200 a day for somebody who plays them all. */
    public const DAILY_GAME_WIN = 25;

    /** All eight in one day, on top of the eight wins. */
    public const DAILY_SWEEP_BONUS = 100;

    // ------------------------------------------------------------- closing

    public const SET_BONUS_BASE = 25;
    public const SET_BONUS_PER_CARD = 6;
    public const SET_BONUS_PER_RARE_CARD = 40;
    public const SET_BONUS_MAX = 600;

    /**
     * What finishing a set pays: a flat opening, something per card, and considerably more
     * per card that was Rare or better — so completing a small set of hard cards is worth
     * more than completing a large set of easy ones, which is the right way round.
     */
    public static function setBonus(int $cards, int $rarePlusCards): int
    {
        return min(
            self::SET_BONUS_MAX,
            self::SET_BONUS_BASE + self::SET_BONUS_PER_CARD * $cards + self::SET_BONUS_PER_RARE_CARD * $rarePlusCards
        );
    }

    /** What reaching the nth rung of a feat pays, 1-indexed. */
    public static function featReward(int $tier): int
    {
        return match (true) {
            $tier <= 0 => 0,
            1 === $tier => 50,
            2 === $tier => 100,
            3 === $tier => 200,
            default => 400,
        };
    }
}
