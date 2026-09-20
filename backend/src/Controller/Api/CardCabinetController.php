<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardSetFamily;
use App\Exception\CardException;
use App\Message\RebuildCardCatalogueMessage;
use App\Service\Card\CardCabinetService;
use App\Service\Card\CardPackOpener;
use App\Service\Card\CardSetService;
use App\Service\Card\CardShowcaseService;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Collecting is a write, and writes belong to the account making them.
 *
 * Every action here except the opening read resolves getAuthenticatedUser(), never
 * getViewedUser() — the same rule that keeps import, sync and the games owner-only. A forged
 * profileId therefore cannot open a pack on somebody else's library, spend their jetons or
 * rearrange their showcase; it draws from the caller's own catalogue instead, which is what
 * the cross-account test pins.
 *
 * The one read here, the cabinet itself, does resolve the viewed profile — but hands back
 * only the collection half of it. The till is null unless you are looking at your own.
 */
#[Route('/api/cards/cabinet')]
final class CardCabinetController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly CardCabinetService $cabinets,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse($this->cabinets->toDto(
            $this->profileResolver->getViewedUser(),
            !$this->profileResolver->isViewingOtherProfile()
        ));
    }

    /**
     * The connection grant.
     *
     * Answers 200 whether or not it paid: the client asks on every visit, and "already taken
     * today" is the ordinary case rather than an error. `granted` is what the UI reads.
     */
    #[Route('/daily', methods: ['POST'])]
    public function daily(): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        return new JsonResponse($this->cabinets->claimDailyGrant($user));
    }

    /**
     * Opens a pack.
     *
     * The kind is constrained in the route rather than checked in the body, so
     * POST /packs/mythique is a 404 and not a refusal — the reasoning GameController gives
     * for keeping /reveal off the daily board.
     *
     * Rate-limited because free packs are unlimited by design, which makes this the one
     * write in the app anybody can call in a loop without paying for it. Thirty a minute is
     * far more than a human clicking through a five-second reveal can use, and far less than
     * a script would like.
     */
    #[Route('/packs/{kind}', methods: ['POST'], requirements: ['kind' => 'free|reel|boxset'])]
    public function openPack(
        string $kind,
        Request $request,
        CardPackOpener $opener,
        RateLimiterFactoryInterface $cabinetPacksLimiter,
    ): JsonResponse {
        $user = $this->profileResolver->getAuthenticatedUser();

        if (!$cabinetPacksLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(
                ['error' => 'Trop de paquets ouverts d\'un coup. Reprends dans un instant.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        try {
            $result = $opener->open($user, CardPackKind::from($kind));
        } catch (CardException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($result, Response::HTTP_CREATED);
    }

    #[Route('/sets/claim', methods: ['POST'])]
    public function claimSet(Request $request, CardSetService $sets): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();
        $payload = $this->payload($request);

        $family = CardSetFamily::tryFrom((string) ($payload['family'] ?? ''));
        $key = (string) ($payload['key'] ?? '');

        if (null === $family || '' === $key) {
            return new JsonResponse(['error' => 'Série inconnue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $balance = $sets->claim($user, $family, $key);
        } catch (CardException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['balance' => $balance]);
    }

    #[Route('/showcase', methods: ['PUT'])]
    public function setShowcase(Request $request, CardShowcaseService $showcase): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();
        $payload = $this->payload($request);

        $cardIds = $payload['cardIds'] ?? null;
        if (!\is_array($cardIds)) {
            return new JsonResponse(['error' => 'Vitrine invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $slots = $showcase->set($user, array_map(
                static fn ($id) => \is_string($id) && '' !== $id ? $id : null,
                array_values($cardIds)
            ));
        } catch (CardException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['slots' => $slots]);
    }

    /**
     * Queues a rebuild.
     *
     * 202 and not 200: the catalogue is rebuilt by a worker, and answering as though it were
     * already done would have the client reload into the same numbers and conclude nothing
     * happened.
     */
    #[Route('/rebuild', methods: ['POST'])]
    public function rebuild(MessageBusInterface $bus): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();
        $bus->dispatch(new RebuildCardCatalogueMessage((string) $user->getId()));

        return new JsonResponse(['queued' => true], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
