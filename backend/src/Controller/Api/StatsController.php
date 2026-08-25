<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Profile\ViewedProfileResolver;
use App\Service\Stats\ActorStatsService;
use App\Service\Stats\DirectorStatsService;
use App\Service\Stats\GenreStatsService;
use App\Service\Stats\OverviewStatsService;
use App\Service\Stats\RatingStatsService;
use App\Service\Stats\TimelineStatsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only, so every action reports on the *viewed* profile rather than the caller —
 * ViewedProfileResolver has already refused the request if that profile was never shared.
 */
#[Route('/api/stats')]
final class StatsController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
    ) {
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(OverviewStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getOverview($this->profileResolver->getViewedUser()));
    }

    #[Route('/timeline', methods: ['GET'])]
    public function timeline(Request $request, TimelineStatsService $service): JsonResponse
    {
        $granularity = 'month' === $request->query->get('granularity') ? 'month' : 'year';

        return new JsonResponse($service->getTimeline($this->profileResolver->getViewedUser(), $granularity));
    }

    #[Route('/ratings', methods: ['GET'])]
    public function ratings(RatingStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getRatingStats($this->profileResolver->getViewedUser()));
    }

    #[Route('/genres', methods: ['GET'])]
    public function genres(GenreStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getGenreStats($this->profileResolver->getViewedUser()));
    }

    #[Route('/directors', methods: ['GET'])]
    public function directors(Request $request, DirectorStatsService $service): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        return new JsonResponse($service->getDirectorStats($this->profileResolver->getViewedUser(), $limit));
    }

    #[Route('/actors', methods: ['GET'])]
    public function actors(Request $request, ActorStatsService $service): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        return new JsonResponse($service->getActorStats($this->profileResolver->getViewedUser(), $limit));
    }
}
