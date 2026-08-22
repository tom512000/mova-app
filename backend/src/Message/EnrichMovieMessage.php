<?php

declare(strict_types=1);

namespace App\Message;

final readonly class EnrichMovieMessage
{
    public function __construct(
        public int $movieId,
    ) {
    }
}
