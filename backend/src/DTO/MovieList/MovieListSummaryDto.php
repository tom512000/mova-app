<?php

declare(strict_types=1);

namespace App\DTO\MovieList;

/**
 * A list as the index shows it: enough to recognise it and to see how far through it you are.
 */
final readonly class MovieListSummaryDto
{
    /**
     * @param list<string> $tags
     * @param list<string> $posterUrls the first few posters, in the list's own order
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $tags,
        public string $letterboxdUrl,
        public ?string $createdOn,
        public int $workCount,
        public int $watchedCount,
        public array $posterUrls,
    ) {
    }
}
