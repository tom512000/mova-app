<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Stats\DirectorStatsService;
use App\Service\Stats\GenreStatsService;
use App\Service\Stats\OverviewStatsService;
use App\Service\Stats\RatingStatsService;
use App\Service\Stats\TimelineStatsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/stats')]
final class StatsController
{
    #[Route('/overview', methods: ['GET'])]
    public function overview(OverviewStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getOverview());
    }

    #[Route('/timeline', methods: ['GET'])]
    public function timeline(Request $request, TimelineStatsService $service): JsonResponse
    {
        $granularity = 'month' === $request->query->get('granularity') ? 'month' : 'year';

        return new JsonResponse($service->getTimeline($granularity));
    }

    #[Route('/ratings', methods: ['GET'])]
    public function ratings(RatingStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getRatingStats());
    }

    #[Route('/genres', methods: ['GET'])]
    public function genres(GenreStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getGenreStats());
    }

    #[Route('/directors', methods: ['GET'])]
    public function directors(Request $request, DirectorStatsService $service): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        return new JsonResponse($service->getDirectorStats($limit));
    }
}
