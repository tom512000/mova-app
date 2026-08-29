<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class WatchDto
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public string $id,
        public ?string $watchedDate,
        public ?float $rating,
        public bool $isRewatch,
        public ?string $reviewText,
        public bool $containsSpoilers,
        public array $tags,
    ) {
    }
}
