<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\MediaType;

final readonly class MovieDetailDto
{
    /**
     * @param string[]    $genres
     * @param string[]    $countries
     * @param CreditDto[] $directors who directed it — always empty on a series, which has
     *                               no director of record
     * @param CreditDto[] $creators  who a series is by; always empty on a film
     * @param CreditDto[] $cast
     * @param WatchDto[]  $watches
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $originalTitle,
        public ?int $releaseYear,
        /** A film's running time; a series' total across every episode. */
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
        public array $creators,
        public array $cast,
        public array $watches,
        public MediaType $mediaType = MediaType::MOVIE,
        /** Series only. Null on a film, which is how the client tells the two apart. */
        public ?int $seasonCount = null,
        public ?int $episodeCount = null,
        public ?string $lastAirDate = null,
    ) {
    }
}
