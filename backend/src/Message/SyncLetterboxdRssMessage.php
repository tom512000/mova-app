<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Carries the account to sync. The shared worker handles messages from every user, so the
 * owner has to travel with the job — there is no ambient "current user" in a worker process.
 */
final readonly class SyncLetterboxdRssMessage
{
    public function __construct(
        public int $userId,
    ) {
    }
}
