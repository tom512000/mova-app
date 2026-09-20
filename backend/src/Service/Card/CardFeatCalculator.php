<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardFeatDto;
use App\Entity\Enum\CardFeat;

/**
 * Turns a bundle of tallies into the ten feats.
 *
 * Pure, and it never sees a database — the same split TrophyCalculator makes, and for the
 * same reason: the rules are what is worth testing, and they are testable without one. The
 * single query that produces the tallies lives in CardFeatService.
 */
final class CardFeatCalculator
{
    /**
     * @param array<string, int> $tallies one per feat, keyed by the feat's value
     *
     * @return list<CardFeatDto>
     */
    public function calculate(array $tallies): array
    {
        $feats = [];

        foreach (CardFeat::cases() as $feat) {
            $value = max(0, $tallies[$feat->value] ?? 0);
            $tiers = $feat->tiers();

            $level = 0;
            foreach ($tiers as $tier) {
                if ($value >= $tier) {
                    ++$level;
                }
            }

            $feats[] = new CardFeatDto(
                key: $feat,
                family: $feat->family(),
                level: $level,
                value: $value,
                // The rung stood on, or the first one while nothing is won — so the client
                // always has a number to measure the progress bar against.
                currentTier: $tiers[max(0, $level - 1)],
                nextTier: $tiers[$level] ?? null,
            );
        }

        return $feats;
    }
}
