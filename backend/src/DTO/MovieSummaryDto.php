<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\MediaType;

final readonly class MovieSummaryDto
{
    public function __construct(
        public string $id,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public ?float $myAverageRating,
        public int $watchCount,
        /**
         * Read by the watchlist, where the question is whether a film fits into the evening
         * that is left. The cards elsewhere do not show it.
         */
        public ?int $runtimeMinutes,
        public EnrichmentStatus $enrichmentStatus,
        public MediaType $mediaType = MediaType::MOVIE,
    ) {
    }
}
