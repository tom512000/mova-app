<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

use App\Entity\Enum\MediaType;

/** One work named by the retrospective, with just enough to draw a poster and link to it. */
final readonly class RetrospectiveWorkDto
{
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public MediaType $mediaType,
        public ?float $rating,
        public ?string $watchedDate,
    ) {
    }
}
