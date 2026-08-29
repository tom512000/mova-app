<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * One submitted ordering and the only thing said back about it: which slots were right.
 *
 * Not which film belongs where, and not whether a film is too early or too late — either
 * would collapse the puzzle in a single move. "This slot is correct" is enough to reason
 * from and little enough to keep three attempts worth playing.
 */
final readonly class TimelineAttemptDto
{
    /**
     * @param list<string> $order   the film ids, oldest first, as submitted
     * @param list<bool>   $correct one per slot, aligned with $order
     */
    public function __construct(
        public array $order,
        public array $correct,
        public int $correctCount,
    ) {
    }
}
