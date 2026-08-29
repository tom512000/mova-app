<?php

declare(strict_types=1);

namespace App\DTO\Game;

/**
 * One film to be placed on the timeline.
 *
 * `releaseYear` is the answer, so it stays null for the whole run and is filled in only
 * once the board is settled — the same rule the poster game applies to its artwork.
 */
final readonly class TimelineCardDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?string $posterUrl,
        public ?int $releaseYear = null,
    ) {
    }
}
