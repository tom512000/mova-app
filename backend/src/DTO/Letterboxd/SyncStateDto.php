<?php

declare(strict_types=1);

namespace App\DTO\Letterboxd;

final readonly class SyncStateDto
{
    public function __construct(
        public bool $configured,
        public bool $autoSyncEnabled,
        public ?string $username,
        public ?string $lastSyncedAt,
        public ?string $lastSyncStatus,
        public ?string $lastSyncError,
        public int $lastRunWatchesImported,
    ) {
    }
}
