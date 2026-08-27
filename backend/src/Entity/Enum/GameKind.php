<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Which game a session belongs to. All three hide a film from the player's own library and
 * all three are played by naming other films — they only differ in what a guess buys you.
 */
enum GameKind: string
{
    /** Each wrong guess unlocks one more fact about the answer. */
    case CLUE = 'clue';

    /** Each guess is laid next to the answer, attribute by attribute. */
    case COMPARE = 'compare';

    /** The answer's poster, blown up from a handful of pixels, sharpening one rung per guess. */
    case POSTER = 'poster';
}
