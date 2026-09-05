<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Echoes back the studio a listing was narrowed to, for the same reason PersonFilterDto
 * exists: the client arrives with an id only, and resolving the name here saves it a second
 * round trip just to label the filter chip.
 */
final readonly class StudioFilterDto
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
