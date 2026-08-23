<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncLetterboxdRssMessage;
use App\Service\Letterboxd\LetterboxdRssSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncLetterboxdRssMessageHandler
{
    public function __construct(
        private readonly LetterboxdRssSyncService $syncService,
    ) {
    }

    public function __invoke(SyncLetterboxdRssMessage $message): void
    {
        $this->syncService->sync();
    }
}
