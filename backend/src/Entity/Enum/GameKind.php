<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Which game a session belongs to. Both hide a film from the player's own library and both
 * are played by naming other films — they only differ in what a guess tells you.
 */
enum GameKind: string
{
    /** Each wrong guess unlocks one more fact about the answer. */
    case CLUE = 'clue';

    /** Each guess is laid next to the answer, attribute by attribute. */
    case COMPARE = 'compare';
}
