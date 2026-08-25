<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Letterboxd\SyncStateDto;
use App\Message\SyncLetterboxdRssMessage;
use App\Repository\LetterboxdSyncStateRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Letterboxd account to sync now lives on the User (it used to be the app-wide
 * LETTERBOXD_USERNAME env var, which cannot express two accounts). Like import, this only
 * ever acts on the authenticated user — never on a profile that was shared with them.
 */
#[Route('/api/sync/letterboxd')]
final class SyncController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
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
        $user = $this->profileResolver->getAuthenticatedUser();

        if (null === $user->getLetterboxdUsername()) {
            return new JsonResponse(['error' => 'Aucun compte Letterboxd configuré sur ce profil.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->messageBus->dispatch(new SyncLetterboxdRssMessage((int) $user->getId()));

        return new JsonResponse($this->buildDto(), Response::HTTP_ACCEPTED);
    }

    private function buildDto(): SyncStateDto
    {
        $user = $this->profileResolver->getAuthenticatedUser();
        $username = $user->getLetterboxdUsername();
        $state = null !== $username ? $this->syncStateRepository->findOneByUser($user) : null;

        return new SyncStateDto(
            configured: null !== $username,
            autoSyncEnabled: $user->isRssSyncEnabled(),
            username: $username,
            lastSyncedAt: $state?->getLastSyncedAt()?->format('c'),
            lastSyncStatus: $state?->getLastSyncStatus(),
            lastSyncError: $state?->getLastSyncError(),
            lastRunWatchesImported: $state?->getLastRunWatchesImported() ?? 0,
        );
    }
}
