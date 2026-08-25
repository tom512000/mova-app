<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangePasswordRequest
{
    public function __construct(
        /**
         * Required even though the caller is already authenticated: it is what stops a
         * borrowed session (an unattended browser, a stolen cookie) from being turned into
         * permanent account takeover.
         */
        #[Assert\NotBlank(message: 'Le mot de passe actuel est obligatoire.')]
        public string $currentPassword = '',

        #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.')]
        #[Assert\Length(
            min: 8,
            max: 4096,
            minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
            maxMessage: 'Le mot de passe est trop long.',
        )]
        public string $newPassword = '',
    ) {
    }
}
