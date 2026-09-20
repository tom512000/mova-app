<?php

declare(strict_types=1);

namespace App\DTO\Card;

/**
 * The state of one Cabinet.
 *
 * Split in two on purpose. Everything above `balance` describes a *collection*, and a
 * collection is worth showing to somebody looking at a shared profile. Everything from
 * `balance` down is the till — the money, the counters, what can be claimed — and it is null
 * while another profile is being viewed, because it belongs to the person doing the looking
 * and not to the library being looked at.
 */
final readonly class CabinetDto
{
    public function __construct(
        public string $ownerDisplayName,
        /** Works watched at the last rebuild — what the gate reads. */
        public int $workCount,
        public int $cardCount,
        public int $ownedCount,
        public int $minimumWorks,
        public bool $unlocked,
        public ?string $catalogueBuiltAt,
        /** Null while viewing somebody else's profile. */
        public ?int $balance,
        public ?int $lifetimeEarned,
        public ?int $lifetimeSpent,
        public ?int $packsOpened,
        public ?int $streakDays,
        public ?bool $dailyGrantAvailable,
        public ?string $nextGrantAt,
        /** Jetons still claimable from free-pack duplicates today. */
        public ?int $freeSalvageLeft,
    ) {
    }
}
