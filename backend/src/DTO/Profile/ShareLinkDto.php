<?php

declare(strict_types=1);

namespace App\DTO\Profile;

final readonly class ShareLinkDto
{
    public function __construct(
        public string $token,
        public string $createdAt,
    ) {
    }
}
