<?php

declare(strict_types=1);

namespace App\Service\Card;

use Random\Randomizer;

/**
 * The real dice.
 *
 * Randomizer and not rand() or mt_rand(): it draws from the platform CSPRNG and, more to
 * the point here, getInt() is uniform over an inclusive range without the modulo bias that
 * `rand() % n` introduces. Pack odds published to four significant figures deserve a
 * generator that actually honours them.
 *
 * Autowiring resolves CardRandomSourceInterface to this class because it is the only
 * implementation under src/ — the fake lives in tests/.
 */
final class CardRandomSource implements CardRandomSourceInterface
{
    private readonly Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer();
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }
}
