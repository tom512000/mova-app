<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\Profile\ProfileSummaryDto;
use App\DTO\Profile\UserDto;
use App\Entity\User;

final class UserMapper
{
    public function toDto(User $user): UserDto
    {
        return new UserDto(
            id: (int) $user->getId(),
            email: $user->getEmail(),
            displayName: $user->getDisplayName(),
            letterboxdUsername: $user->getLetterboxdUsername(),
            rssSyncEnabled: $user->isRssSyncEnabled(),
        );
    }

    public function toSummaryDto(User $user, User $viewer): ProfileSummaryDto
    {
        return new ProfileSummaryDto(
            id: (int) $user->getId(),
            displayName: $user->getDisplayName(),
            isSelf: $user->getId() === $viewer->getId(),
        );
    }
}
