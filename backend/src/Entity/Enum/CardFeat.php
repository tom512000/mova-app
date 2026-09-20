<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The card feats: a fixed, hand-written list, exactly as Trophy is.
 *
 * The same reasoning applies — a set is generated (there is one per decade, per genre, per
 * studio a library holds enough of, and there are hundreds), while a feat is one named thing
 * with a rung ladder, and there are ten. Mixed together the ten would vanish.
 *
 * Language-free like every other enum here: the names and the one-line jokes belong with the
 * French copy in the frontend. What this carries is what the arithmetic needs — which shelf,
 * and where the rungs are.
 *
 * Every feat is derived, stored nowhere, computed from the catalogue and the cabinet on each
 * request, and dated from `card.first_owned_at`. That is the same argument TrophyDto makes,
 * and it is what lets the frontend reuse TrophyShelf's locked/unlocked vocabulary wholesale
 * rather than inventing a third achievement idiom.
 */
enum CardFeat: string
{
    // Collection
    case COLLECTOR = 'collector';
    case PACK_RAT = 'pack_rat';
    case FIRST_LEGENDARY = 'first_legendary';

    // Complétion
    case COMPLETIONIST = 'completionist';
    case FULL_HOUSE = 'full_house';
    case SAGA = 'saga';

    // Économie
    case BIG_SPENDER = 'big_spender';
    case SALVAGE = 'salvage';

    // Spécialité
    case AUTEUR = 'auteur';
    case MOGUL = 'mogul';

    public function family(): CardFeatFamily
    {
        return match ($this) {
            self::COLLECTOR, self::PACK_RAT, self::FIRST_LEGENDARY => CardFeatFamily::COLLECTION,
            self::COMPLETIONIST, self::FULL_HOUSE, self::SAGA => CardFeatFamily::COMPLETION,
            self::BIG_SPENDER, self::SALVAGE => CardFeatFamily::ECONOMY,
            self::AUTEUR, self::MOGUL => CardFeatFamily::SPECIALITY,
        };
    }

    /**
     * The rungs, ascending, in whatever unit the feat counts: cards owned, packs opened,
     * sets completed, jetons moved, or cards of one subject. A feat with a single rung is
     * won once and has no levels to show — FIRST_LEGENDARY is the obvious one, and it is
     * single-rung on purpose: there are around twenty Légendaires in a catalogue, and a
     * ladder over them would be a ladder nobody climbs.
     *
     * @return list<int>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::COLLECTOR => [25, 100, 250, 500, 1000],
            self::PACK_RAT => [10, 50, 200, 1000],
            self::COMPLETIONIST => [1, 3, 10, 25],
            // Jetons, and the rungs are deliberately far apart: at a realistic 400 a day,
            // the last rung of each is a season of play rather than a week.
            self::BIG_SPENDER => [500, 5000, 25000, 100000],
            self::SALVAGE => [250, 2500, 15000, 60000],
            self::AUTEUR => [5, 25, 75, 150],
            self::MOGUL => [3, 10, 30, 75],
            // The three that are won by doing one whole thing, not by doing many of them.
            default => [1],
        };
    }
}
