<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * The timeline as the player may see it.
 *
 * `cards` are in the shuffled order they were dealt and stay in it: the client keeps the
 * arrangement the player is building, and re-dealing them on every read would throw that
 * away. `solution` is the answer and appears only once the run is over.
 */
final readonly class TimelineBoardDto
{
    /**
     * @param list<TimelineCardDto>    $cards    as dealt, never re-shuffled mid-run
     * @param list<TimelineAttemptDto> $attempts oldest first
     * @param list<string>|null        $solution the film ids in true release order
     */
    public function __construct(
        public array $cards,
        public array $attempts,
        public ?array $solution,
    ) {
    }
}
