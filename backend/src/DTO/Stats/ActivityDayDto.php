<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class ActivityDayDto
{
    public function __construct(
        public string $date,
        public int $watchCount,
    ) {
    }
}
