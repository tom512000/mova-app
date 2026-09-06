<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/** The month that carried the year. */
final readonly class RetrospectiveMonthDto
{
    public function __construct(
        /** 1 to 12, so the client can name it in its own language. */
        public int $month,
        public int $watchCount,
        /** What the other eleven averaged, to say how far above the rest this one sits. */
        public float $averageMonthCount,
    ) {
    }
}
