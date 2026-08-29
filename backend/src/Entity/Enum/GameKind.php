<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Which game a session belongs to.
 *
 * Six of them hide one film from the player's own library and differ only in what is shown
 * of it; the last two hide nothing at all and ask about the library itself — which of two
 * films you rated higher, and in what order five of them came out. That split is what
 * decides the shape of a move, so it is worth reading off this list:
 *
 *   naming a film  → CLUE, COMPARE, POSTER, HANGMAN, TAGLINE, BACKDROP
 *   a letter       → HANGMAN
 *   picking a side → DUEL
 *   an ordering    → TIMELINE
 */
enum GameKind: string
{
    /** Each wrong guess unlocks one more fact about the answer. */
    case CLUE = 'clue';

    /** Each guess is laid next to the answer, attribute by attribute. */
    case COMPARE = 'compare';

    /** The answer's poster, blown up from a handful of pixels, sharpening one rung per guess. */
    case POSTER = 'poster';

    /** The answer's title, masked letter by letter. The only one played on letters. */
    case HANGMAN = 'hangman';

    /** The answer's own marketing line, and nothing else until the first miss. */
    case TAGLINE = 'tagline';

    /** Like POSTER, but on the backdrop: no title, no poster framing, much harder. */
    case BACKDROP = 'backdrop';

    /** Two films side by side — which did you rate higher? Played as a streak. */
    case DUEL = 'duel';

    /** Five films to put back in release order. Deduction rather than recall. */
    case TIMELINE = 'timeline';

    /**
     * Whether a run is played by naming films from the library.
     *
     * The two odd ones out have their own move and their own route, and this is the guard
     * that keeps a picked side or a submitted ordering from arriving at /guess.
     */
    public function isNamedByFilm(): bool
    {
        return !\in_array($this, [self::DUEL, self::TIMELINE], true);
    }
}
