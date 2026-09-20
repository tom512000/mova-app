<?php

declare(strict_types=1);

namespace App\Service\Card;

/**
 * Where a pack's luck comes from.
 *
 * An interface for one reason: a test that wants to prove "a free pack never yields a
 * Légendaire" or "pity fires on the thirtieth card and not the twenty-ninth" has to be able
 * to say what the dice did. Everything else about the draw is deterministic, so this is the
 * only seam the tests need — the same reason TmdbClientInterface exists.
 */
interface CardRandomSourceInterface
{
    /**
     * An integer in [$min, $max], both ends included.
     */
    public function int(int $min, int $max): int;
}
