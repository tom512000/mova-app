<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class TimelineBucketDto
{
    public function __construct(
        public string $period,
        public int $watchCount,
        public int $watchTimeMinutes,
        public ?float $averageRating,
    ) {
    }
}
