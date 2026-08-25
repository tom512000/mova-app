<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncLetterboxdRssMessage;
use App\Repository\UserRepository;
use App\Service\Letterboxd\LetterboxdRssSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncLetterboxdRssMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LetterboxdRssSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncLetterboxdRssMessage $message): void
    {
        $user = $this->userRepository->find($message->userId);
        if (null === $user) {
            // The account was deleted between scheduling and handling. Nothing to sync, and
            // retrying would never succeed.
            $this->logger->warning('Skipping Letterboxd sync for unknown user {userId}', ['userId' => $message->userId]);

            return;
        }

        $this->syncService->sync($user);
    }
}
