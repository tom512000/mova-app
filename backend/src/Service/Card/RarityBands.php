<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\Enum\CardRarity;

/**
 * Where the six tiers cut the catalogue.
 *
 * The central decision of the whole feature: a card's tier comes from its *rank* among all
 * of that account's cards, not from an absolute score threshold. A threshold would mean a
 * small library holds no Légendaires and a huge one holds hundreds, and the advertised pack
 * odds would be a different promise to every player. Rank-and-cut gives exactly
 * CEIL(N × share) cards in every band whatever N is, which is what makes the odds honest.
 *
 * The price is that a card's live tier can move because a *different* card changed. That is
 * inherent to percentile banding, and it is precisely why Card::$ownedRarity freezes what
 * was pulled.
 *
 * **The bands are cut per subject, not across the catalogue.** The four subjects are scored
 * on incommensurable scales and no amount of weight-tuning makes them comparable: a studio
 * card aggregates thirty-five works and a film card aggregates one, so the studio saturates
 * every log curve it has while the film never maxes more than two of its four components.
 * Cut globally, the first calibration run against a real 744-work library put twenty-nine
 * Légendaires on the shelf and every single one was a studio or a saga — no films, no
 * people, which is an absurd result for a game about somebody's film library.
 *
 * Cutting within each subject removes the question instead of tuning around it. Every
 * subject gets the same shares, so the top shelf holds the best films *and* the best people
 * *and* the two best studios; and because each subject contributes the same fraction, the
 * union still comes out at the advertised share of the whole catalogue. The pack odds stay
 * honest and nothing has to be re-tuned when a library changes shape.
 *
 * **Why the shares are integers in parts per million.** The rule is written twice — once
 * here for display and the tests, once as SQL inside the ranking UPDATE — and the two have
 * to agree exactly on every boundary. With floats they do not: PHP reads 0.03 as
 * 0.0299999999999999988…, so 1000 × 0.03 is 30.000000000000004 and ceils to 31, while
 * Postgres' exact-decimal numeric makes it 30 and ceils to 30. One card would land in a
 * different band depending on which language asked. Integer ppm and a ceiling division
 * remove the question rather than test around it.
 */
final class RarityBands
{
    private const SCALE = 1000000;

    /**
     * The cumulative share of the catalogue, from the top, at which each tier stops —
     * 0.6 %, then 3 %, 8 %, 20 %, 45 %, and Commune takes the rest.
     *
     * Read as band widths that is 0.6 / 2.4 / 5 / 12 / 25 / 55, which on a realistic 3 500
     * card catalogue is roughly 21 Légendaires, 85 Ultra Rares and 1 900 Communes. Twenty
     * Légendaires is the number this was tuned for: few enough that owning one is an event,
     * many enough that the album has a top shelf worth filling.
     *
     * Declared top-first because that is the order the SQL CASE has to test them in.
     */
    private const CUMULATIVE_PPM = [
        CardRarity::LEGENDARY->value => 6000,
        CardRarity::ULTRA_RARE->value => 30000,
        CardRarity::SUPER_RARE->value => 80000,
        CardRarity::RARE->value => 200000,
        CardRarity::UNCOMMON->value => 450000,
    ];

    /**
     * The tier at a given rank, 1 being the highest-scoring card in the catalogue.
     *
     * Position-based and not percentile-based on purpose: this is the function the SQL has
     * to agree with, so it does the same ceiling division on the same integers rather than a
     * lookup against a float that was derived a different way.
     */
    public static function rarityFor(int $position, int $total): CardRarity
    {
        $floor = 1;
        foreach (self::CUMULATIVE_PPM as $rarity => $ppm) {
            if ($position <= max($floor, self::cutoff($total, $ppm))) {
                return CardRarity::from($rarity);
            }
            ++$floor;
        }

        return CardRarity::COMMON;
    }

    /**
     * The last rank still inside a band, floors aside: CEIL(total × ppm / 1_000_000), done
     * as an integer ceiling division so it cannot disagree with the SQL.
     */
    public static function cutoff(int $total, int $ppm): int
    {
        return intdiv($total * $ppm + self::SCALE - 1, self::SCALE);
    }

    /**
     * The same rule as SQL, for the one UPDATE that applies it to every row at once.
     *
     * The GREATEST floors are not cosmetic. On a catalogue small enough that
     * CEIL(N × 0.006) rounds to zero, the Légendaire band would be empty — and a pack that
     * guarantees a tier has to find something to draw from it. The floors 1, 2, 3, 4, 5 give
     * every band at least one card as soon as the catalogue has six.
     *
     * Interpolation is safe and deliberate: what reaches the string is the integer constants
     * above and two column expressions named by this file's own caller. Nothing a request
     * supplied can get here.
     */
    public static function caseExpression(string $positionColumn, string $totalColumn): string
    {
        $case = 'CASE';
        $floor = 1;

        foreach (self::CUMULATIVE_PPM as $rarity => $ppm) {
            $case .= sprintf(
                " WHEN %s <= GREATEST(%d, CEIL(%s::numeric * %d / %d)) THEN '%s'",
                $positionColumn,
                $floor,
                $totalColumn,
                $ppm,
                self::SCALE,
                $rarity
            );
            ++$floor;
        }

        return $case.sprintf(" ELSE '%s' END", CardRarity::COMMON->value);
    }

    /**
     * SQL: a tier as a sortable integer, 5 for Légendaire down to 0 for Commune.
     *
     * Postgres orders a varchar enum column alphabetically, which would file Commune above
     * Super Rare. The album's default order wants the good shelf first, so the ladder has to
     * be spelled out — off CardRarity::cases(), which is already its own reference for the
     * ordering, so this cannot drift from CardRarity::rank().
     */
    public static function rankExpression(string $rarityColumn): string
    {
        $case = 'CASE '.$rarityColumn;
        foreach (CardRarity::cases() as $rarity) {
            $case .= sprintf(" WHEN '%s' THEN %d", $rarity->value, $rarity->rank());
        }

        return $case.' ELSE 0 END';
    }

    /**
     * Each tier's width as a fraction of the catalogue, top tier first. Display and tests
     * only — nothing that assigns a rarity reads this.
     *
     * @return array<string, float>
     */
    public static function shares(): array
    {
        $shares = [];
        $previous = 0;

        foreach (self::CUMULATIVE_PPM as $rarity => $ppm) {
            $shares[$rarity] = ($ppm - $previous) / self::SCALE;
            $previous = $ppm;
        }

        $shares[CardRarity::COMMON->value] = (self::SCALE - $previous) / self::SCALE;

        return $shares;
    }
}
