<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * A duel that has been played: both sides with their ratings now visible, and which one
 * the player backed.
 */
final readonly class DuelRoundDto
{
    /**
     * @param list<DuelCardDto> $cards exactly two, in the order they were shown
     */
    public function __construct(
        public array $cards,
        public string $pickedId,
        public bool $correct,
    ) {
    }
}
