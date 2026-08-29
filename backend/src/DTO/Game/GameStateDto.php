<?php

declare(strict_types=1);

namespace App\DTO\Game;

use App\DTO\MovieSummaryDto;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\GameStatus;

/**
 * Everything the player is allowed to know. `answer` stays null until the run is over —
 * that omission is the game, so it is enforced here rather than in the controller.
 *
 * Most of the fields below belong to one game each and are null everywhere else. That is
 * not a union type dressed up as an object: the bookkeeping at the top (status, attempts,
 * mode, date) is genuinely shared by all eight, and it is the *only* thing they share, so
 * one envelope with optional compartments beats eight near-identical shapes on the wire.
 */
final readonly class GameStateDto
{
    /**
     * @param list<ClueDto>         $clues    only the ones unlocked so far, and empty in the
     *                                        games that have no ladder
     * @param list<GameGuessDto>    $guesses  in the order they were played
     * @param string|null           $tagline  the answer's own marketing line, in "L'accroche"
     *                                        only — where it is the opening card rather than
     *                                        a clue, which is why it is not a ClueDto
     * @param ArtworkPixelsDto|null $artwork  the picture at the sharpness earned so far, in
     *                                        the two pixel games. It belongs in this DTO
     *                                        rather than behind an image route so that
     *                                        everything the player may see still passes
     *                                        through one gate.
     * @param HangmanBoardDto|null  $hangman  the masked title, in the hangman only — same
     *                                        rule, same gate
     * @param DuelBoardDto|null     $duel     the pair on the table and the streak behind it
     * @param TimelineBoardDto|null $timeline the five films and the orderings tried on them
     */
    public function __construct(
        public GameKind $game,
        public GameMode $mode,
        public GameStatus $status,
        public int $attemptsUsed,
        public int $maxAttempts,
        public array $clues,
        public array $guesses,
        public ?MovieSummaryDto $answer,
        public ?string $puzzleDate,
        public ?string $tagline = null,
        public ?ArtworkPixelsDto $artwork = null,
        public ?HangmanBoardDto $hangman = null,
        public ?DuelBoardDto $duel = null,
        public ?TimelineBoardDto $timeline = null,
    ) {
    }
}
