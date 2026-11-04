<?php

declare(strict_types=1);

namespace App\DTO\MovieList;

use App\DTO\MovieSummaryDto;

final readonly class MovieListEntryDto
{
    public function __construct(
        public int $position,
        public ?string $note,
        public MovieSummaryDto $movie,
    ) {
    }
}
