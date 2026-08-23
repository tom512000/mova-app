<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Letterboxd\SyncStateDto;
use App\Message\SyncLetterboxdRssMessage;
use App\Repository\LetterboxdSyncStateRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sync/letterboxd')]
final class SyncController
{
    public function __construct(
        #[Autowire('%app.letterboxd.username%')]
        private readonly string $username,
        #[Autowire('%app.letterboxd.rss_sync_enabled%')]
        private readonly bool $autoSyncEnabled,
        private readonly LetterboxdSyncStateRepository $syncStateRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->buildDto());
    }

    #[Route('', methods: ['POST'])]
    public function trigger(): JsonResponse
    {
        if ('' === $this->username) {
            return new JsonResponse(['error' => 'LETTERBOXD_USERNAME non configuré.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->messageBus->dispatch(new SyncLetterboxdRssMessage());

        return new JsonResponse($this->buildDto(), Response::HTTP_ACCEPTED);
    }

    private function buildDto(): SyncStateDto
    {
        $state = '' !== $this->username ? $this->syncStateRepository->findOneByUsername($this->username) : null;

        return new SyncStateDto(
            configured: '' !== $this->username,
            autoSyncEnabled: $this->autoSyncEnabled,
            username: '' !== $this->username ? $this->username : null,
            lastSyncedAt: $state?->getLastSyncedAt()?->format('c'),
            lastSyncStatus: $state?->getLastSyncStatus(),
            lastSyncError: $state?->getLastSyncError(),
            lastRunWatchesImported: $state?->getLastRunWatchesImported() ?? 0,
        );
    }
}
