<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CreditDto
{
    public function __construct(
        public string $personId,
        public string $name,
        public ?string $profileUrl,
        public ?string $characterName,
    ) {
    }
}
