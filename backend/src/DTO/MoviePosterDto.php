<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\MediaType;

/**
 * One exhibit on the museum wall — only what a poster needs to hang there and be named.
 *
 * Deliberately thinner than MovieSummaryDto: the wall asks for the whole library at once,
 * so every field carried here is paid for seven hundred times over.
 */
final readonly class MoviePosterDto
{
    public function __construct(
        public string $id,
        public string $title,
        public ?int $releaseYear,
        public string $posterUrl,
        public ?float $myAverageRating,
        public MediaType $mediaType = MediaType::MOVIE,
    ) {
    }
}
