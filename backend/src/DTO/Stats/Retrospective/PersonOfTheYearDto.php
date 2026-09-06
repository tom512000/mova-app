<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

use App\Entity\Enum\CreditRole;

/**
 * The face of the year, one per job that says something about a choice.
 *
 * Direction and performance only. A producer credit is not a reason anybody picked a film,
 * and counting it would have handed this library its year to a production executive credited
 * on nineteen comedies nobody watched for her.
 */
final readonly class PersonOfTheYearDto
{
    public function __construct(
        public string $personId,
        public string $name,
        public ?string $profileUrl,
        public CreditRole $role,
        /** Distinct works of theirs watched during the year. */
        public int $workCount,
    ) {
    }
}
