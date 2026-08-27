<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * The hangman board as the player is allowed to see it.
 *
 * `chars` is one slot per character of the title, holding the character when it is on the
 * board and null while it is still hidden. The title itself never appears — a client that
 * received it could simply read the answer off the wire.
 */
final readonly class HangmanBoardDto
{
    /**
     * @param list<string|null> $chars     null for a letter still to be found; the character
     *                                     itself for anything revealed, and for the spaces,
     *                                     digits and punctuation that start out shown
     * @param list<string>      $tried     every letter played, in order
     * @param list<string>      $wrong     the ones the title does not contain
     * @param int               $livesLeft wrong letters and wrong films both cost one
     */
    public function __construct(
        public array $chars,
        public array $tried,
        public array $wrong,
        public int $livesLeft,
        public int $lives,
    ) {
    }
}
