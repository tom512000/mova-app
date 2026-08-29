<?php

declare(strict_types=1);

namespace App\DTO\Profile;

/**
 * A profile as seen from the outside: enough to label it in the header switcher and
 * nothing more. No email, so having been granted access to someone's film diary never
 * also hands out their login identifier.
 */
final readonly class ProfileSummaryDto
{
    public function __construct(
        public string $id,
        public string $displayName,
        public bool $isSelf,
    ) {
    }
}
