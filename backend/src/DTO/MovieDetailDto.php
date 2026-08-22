<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\EnrichmentStatus;

final readonly class MovieDetailDto
{
    /**
     * @param string[]    $genres
     * @param string[]    $countries
     * @param CreditDto[] $directors
     * @param CreditDto[] $cast
     * @param WatchDto[]  $watches
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $originalTitle,
        public ?int $releaseYear,
        public ?int $runtimeMinutes,
        public ?string $synopsis,
        public ?string $posterUrl,
        public ?string $backdropUrl,
        public ?float $tmdbVoteAverage,
        public ?string $imdbId,
        public EnrichmentStatus $enrichmentStatus,
        public array $genres,
        public array $countries,
        public array $directors,
        public array $cast,
        public array $watches,
    ) {
    }
}
