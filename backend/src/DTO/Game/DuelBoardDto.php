<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * The duel as the player may see it: the pair on the table, the streak behind it, and the
 * rounds already settled.
 *
 * `cards` is null once the run is over — there is no next pair to draw, and leaving the
 * dead one on the table would invite a click that cannot land.
 */
final readonly class DuelBoardDto
{
    /**
     * @param list<DuelCardDto>|null $cards   exactly two while the run is open
     * @param list<DuelRoundDto>     $history oldest first, the losing round last
     */
    public function __construct(
        public ?array $cards,
        public array $history,
        public int $streak,
        public int $best,
    ) {
    }
}
