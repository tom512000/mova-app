<?php

declare(strict_types=1);

namespace App\DTO\Person;

use App\Entity\Enum\CreditRole;

/**
 * One job this person holds in the library, counted on its own.
 *
 * Kept apart rather than folded into a single average because the same person is very often
 * two different propositions: Dany Boon is twenty-one films as an actor and seven as a
 * director, and the two carry different notes. A page that only showed the blend would hide
 * the more interesting half.
 */
final readonly class PersonRoleDto
{
    public function __construct(
        public CreditRole $role,
        /** Works watched in this role — a work credited twice in it still counts once. */
        public int $watchedCount,
        /** Works credited in this role but never watched, watchlist included. */
        public int $unwatchedCount,
        public ?float $averageRating,
    ) {
    }
}
