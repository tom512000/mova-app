<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The six tiers a card can hold.
 *
 * Language-free like every other enum here — "Peu Commune" and the rest of the French copy
 * live in the frontend. What this carries is the ordering, which the arithmetic needs in
 * three separate places: stepping down a tier when a band comes back empty, deciding whether
 * a guaranteed slot should raise a roll or leave it alone, and answering whether a tier may
 * appear in a free pack at all.
 *
 * What this deliberately does *not* carry is how wide each band is, or what a duplicate of
 * one is worth. Those are tuning tables — they will be moved while the game is balanced
 * against a real library — and they live in RarityBands and JetonTariff, which are plain
 * classes full of constants that a unit test can pin. An enum that also held the numbers
 * would make every rebalance a change to the domain model.
 */
enum CardRarity: string
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case SUPER_RARE = 'super_rare';
    case ULTRA_RARE = 'ultra_rare';
    case LEGENDARY = 'legendary';

    /**
     * Position in the ladder, 0 for Commune through 5 for Légendaire.
     *
     * Declared order is the ladder, so the cases below are their own reference — the same
     * trick CreditRole::sortByCreditOrder() uses to avoid restating an ordering that is
     * already written down once.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Whether a free pack may ever contain this tier.
     *
     * The whole free-pack design rests on this returning false for the top two: packs are
     * unlimited and cost nothing, so what keeps them from trivialising the collection is not
     * a cooldown but the fact that the best 3 % of the catalogue is simply not in their pool.
     *
     * This is checked twice on purpose. PackOdds gives the free kind 0.0 for both tiers, and
     * the draw query carries an explicit exclusion built from this method. A mistyped odds
     * row must not be able to leak a Légendaire out of a free pack, and a single guard is one
     * typo away from doing exactly that.
     */
    public function isInFreePool(): bool
    {
        return match ($this) {
            self::ULTRA_RARE, self::LEGENDARY => false,
            default => true,
        };
    }

    /**
     * The tier one rung down, or null at the bottom.
     *
     * Used when a band comes back empty — a catalogue small enough that CEIL(N × 0.006)
     * rounded to one card, and that one card was already drawn earlier in the same pack.
     * Stepping down beats both alternatives: refusing the open after debiting, and drawing
     * the same card twice.
     */
    public function below(): ?self
    {
        return self::cases()[$this->rank() - 1] ?? null;
    }

    /**
     * The tiers at or above this one, ascending. The shape a guaranteed slot needs: a floor
     * of Rare means the last card is rolled against Rare, Super Rare, Ultra Rare, Légendaire
     * and nothing else, so the guarantee can only ever raise a pull.
     *
     * @return list<self>
     */
    public function andAbove(): array
    {
        return \array_slice(self::cases(), $this->rank());
    }
}
