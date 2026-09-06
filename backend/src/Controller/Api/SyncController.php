<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Letterboxd\SyncSettingsRequest;
use App\DTO\Letterboxd\SyncStateDto;
use App\Message\SyncLetterboxdRssMessage;
use App\Repository\LetterboxdSyncStateRepository;
use App\Service\Profile\ViewedProfileResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Letterboxd account to sync lives on the User row, because one installation-wide
 * setting cannot express two accounts. That move left the setting readable and unwritable
 * for a while: the migration seeded it once and nothing could change it afterwards, so a
 * second account had no way to sync at all. PUT closes it.
 *
 * Every action here acts on the authenticated user, never on a profile shared with them —
 * the same rule import follows.
 */
#[Route('/api/sync/letterboxd')]
final class SyncController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly LetterboxdSyncStateRepository $syncStateRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->buildDto());
    }

    /**
     * Points this profile at a Letterboxd account, or unpoints it.
     *
     * getAuthenticatedUser(), never getViewedUser(): this writes, and a profile shared with
     * somebody stays strictly read-only however they got to this screen.
     */
    #[Route('', methods: ['PUT'])]
    public function updateSettings(#[MapRequestPayload] SyncSettingsRequest $request): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        // setLetterboxdUsername() already reads '' as "none", so clearing the field is how
        // somebody stops syncing rather than a state they have to invent a username to reach.
        $user->setLetterboxdUsername($request->letterboxdUsername);

        if ($request->rssSyncEnabled && null === $user->getLetterboxdUsername()) {
            // Refused rather than quietly stored as false. The repository behind the
            // scheduler already ignores an account with no username, so nothing would have
            // broken — but the switch would have come back off with no explanation, which
            // reads as the save having failed.
            return new JsonResponse(
                ['error' => 'Renseigne un pseudo Letterboxd avant d\'activer la synchronisation automatique.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user->setRssSyncEnabled($request->rssSyncEnabled);
        $this->entityManager->flush();

        return new JsonResponse($this->buildDto());
    }

    #[Route('', methods: ['POST'])]
    public function trigger(): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        if (null === $user->getLetterboxdUsername()) {
            return new JsonResponse(['error' => 'Aucun compte Letterboxd configuré sur ce profil.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->messageBus->dispatch(new SyncLetterboxdRssMessage((string) $user->getId()));

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
