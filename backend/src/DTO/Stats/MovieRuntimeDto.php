<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class MovieRuntimeDto
{
    public function __construct(
        public int $movieId,
        public string $title,
        public int $runtimeMinutes,
    ) {
    }
}
