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
        /**
         * True when the viewing was worked out from ratings.csv changing its mind rather
         * than declared in a diary entry. Screens that show a rewatch badge use it to say
         * which kind they are showing — see WatchSource::CSV_RERATING.
         */
        public bool $isDeduced,
        public ?string $reviewText,
        public bool $containsSpoilers,
        public array $tags,
    ) {
    }
}
