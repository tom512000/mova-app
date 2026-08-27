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
 */
final readonly class GameStateDto
{
    /**
     * @param list<ClueDto>      $clues   only the ones unlocked so far, and empty in the two
     *                                    games that have no ladder
     * @param list<GameGuessDto> $guesses in the order they were played
     * @param PosterPixelsDto|null $poster the artwork at the sharpness earned so far, in the
     *                                     poster game only. It belongs in this DTO rather
     *                                     than behind an image route so that everything the
     *                                     player may see still passes through one gate.
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
        public ?PosterPixelsDto $poster = null,
    ) {
    }
}
