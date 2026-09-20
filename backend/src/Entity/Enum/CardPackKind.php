<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The three packs that can be opened.
 *
 * Language-free like every other enum here: "Pochette", "Bobine" and "Coffret" are frontend
 * copy. What this carries is what a pack *is* — how many cards it deals, what it guarantees,
 * and whether it counts towards pity. What a pack *costs* lives in JetonTariff and what it
 * *yields* lives in PackOdds, so that the whole economy can be rebalanced in two files
 * without touching the domain model.
 *
 * The design rests on one asymmetry, and it is worth stating here because three separate
 * places depend on it: FREE is unlimited and costs nothing, and the only things holding it
 * back are that the top two tiers are absent from its pool (CardRarity::isInFreePool) and
 * that it never advances pity. Without the second rule the first would be pointless — a
 * player could grind free packs to charge up a guaranteed Légendaire, and the generosity
 * would have defeated the scarcity.
 */
enum CardPackKind: string
{
    /** Unlimited, free, and deliberately poor: no Ultra Rare, no Légendaire, no pity. */
    case FREE = 'free';

    /** The first paid pack. One card in five is guaranteed Rare or better. */
    case REEL = 'reel';

    /** The big one. Seven cards, a Super Rare floor on the last, and no Commune at all. */
    case BOXSET = 'boxset';

    /**
     * How many cards the pack deals. The last one is the guaranteed slot where there is a
     * floor, so a pack of five rolls four times freely and once against the floor.
     */
    public function cardCount(): int
    {
        return match ($this) {
            self::FREE, self::REEL => 5,
            self::BOXSET => 7,
        };
    }

    /**
     * The floor the last card is rolled against.
     *
     * A floor only ever raises a pull: the roll runs over CardRarity::andAbove(), so a pack
     * cannot hand out something worse than it promised, and it cannot cap something better
     * either. Every pack has one — even the free pack guarantees Peu Commune, which is the
     * whole reason five free cards still feel like an opening rather than a handful of dust.
     */
    public function guaranteedFloor(): CardRarity
    {
        return match ($this) {
            self::FREE => CardRarity::UNCOMMON,
            self::REEL => CardRarity::RARE,
            self::BOXSET => CardRarity::SUPER_RARE,
        };
    }

    /**
     * Whether cards drawn from this pack advance the pity counters. See the class docblock:
     * free packs must not, or the exclusion of the top tiers from their pool buys nothing.
     */
    public function buildsPity(): bool
    {
        return self::FREE !== $this;
    }

    public function isFree(): bool
    {
        return self::FREE === $this;
    }
}
