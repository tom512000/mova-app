<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\EnrichmentStatus;

final readonly class MovieSummaryDto
{
    public function __construct(
        public int $id,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public ?float $myAverageRating,
        public int $watchCount,
        public EnrichmentStatus $enrichmentStatus,
    ) {
    }
}
