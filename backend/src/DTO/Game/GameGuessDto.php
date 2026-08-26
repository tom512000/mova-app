<?php

declare(strict_types=1);

namespace App\DTO\Game;

final readonly class GameGuessDto
{
    public function __construct(
        public int $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public bool $correct,
    ) {
    }
}
