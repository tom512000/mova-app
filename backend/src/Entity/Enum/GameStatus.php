<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GameStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case WON = 'won';
    case LOST = 'lost';

    /**
     * The player asked for the answer instead of playing the run out.
     *
     * Its own case rather than a LOST with a flag, because the two are not the same thing
     * and the screens say so: a lost run was played to the end of its rope, a revealed one
     * was stopped. Reusing LOST would quietly file every "je donne ma langue au chat" under
     * defeats, and the duel's streak wording ("série interrompue") would be a lie about
     * what happened.
     */
    case REVEALED = 'revealed';

    public function isOver(): bool
    {
        return self::IN_PROGRESS !== $this;
    }
}
