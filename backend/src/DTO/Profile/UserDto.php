<?php

declare(strict_types=1);

namespace App\DTO\Profile;

/**
 * The authenticated account, as returned by /api/auth/me and /api/auth/login.
 * Only ever describes the caller — another user's profile is exposed as the far
 * narrower ProfileSummaryDto, which carries no email and no sync configuration.
 */
final readonly class UserDto
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public ?string $letterboxdUsername,
        public bool $rssSyncEnabled,
    ) {
    }
}
