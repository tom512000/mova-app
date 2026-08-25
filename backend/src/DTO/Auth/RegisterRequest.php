<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mapped and validated straight off the request body by #[MapRequestPayload]; a violation
 * never reaches the controller. Email *uniqueness* is checked in the controller instead of
 * with #[UniqueEntity]: that constraint targets a mapped entity, and this is a plain DTO.
 */
final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
        #[Assert\Email(message: 'Cet email n\'est pas valide.')]
        #[Assert\Length(max: 180, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères.')]
        public string $email = '',

        #[Assert\NotBlank(message: 'Le nom affiché est obligatoire.')]
        #[Assert\Length(
            min: 2,
            max: 100,
            minMessage: 'Le nom affiché doit faire au moins {{ limit }} caractères.',
            maxMessage: 'Le nom affiché ne peut pas dépasser {{ limit }} caractères.',
        )]
        public string $displayName = '',

        #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
        #[Assert\Length(
            min: 8,
            // 4096 is Symfony's own cap: bcrypt truncates past 72 bytes, and accepting an
            // unbounded string lets one request burn arbitrary CPU in the hasher.
            max: 4096,
            minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
            maxMessage: 'Le mot de passe est trop long.',
        )]
        public string $password = '',
    ) {
    }
}
