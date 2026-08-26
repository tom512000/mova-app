<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The values a profile can actually filter on. Built from what the profile has watched
 * rather than from the whole catalogue, so the dropdowns never offer a choice that
 * would return an empty page.
 */
final readonly class MovieFacetsDto
{
    /**
     * @param list<string> $genres
     * @param list<int>    $years
     * @param list<float>  $ratings
     */
    public function __construct(
        public array $genres,
        public array $years,
        public array $ratings,
        public bool $hasUnrated,
    ) {
    }
}
