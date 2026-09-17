<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Profile\ViewedProfileResolver;
use App\Service\Trophy\TrophyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The trophy shelf: every trophy in the catalogue, won or not.
 *
 * Unpaged and unfiltered, unlike badges — there are fourteen, and the page draws them all.
 *
 * Read-only, so it reports on the *viewed* profile: a shared profile's trophies are that
 * profile's trophies, which is most of the reason to show anybody a trophy.
 */
#[Route('/api/trophies')]
final class TrophyController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(TrophyService $service): JsonResponse
    {
        return new JsonResponse($service->getTrophies($this->profileResolver->getViewedUser()));
    }
}
