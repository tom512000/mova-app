<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\CreditRole;

/**
 * Echoes back the person a listing was narrowed to. The client arrives with an id only —
 * resolving the name here saves it a second round trip just to label the filter.
 */
final readonly class PersonFilterDto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?CreditRole $role,
    ) {
    }
}
