<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\CreditRole;
use App\Service\Profile\ViewedProfileResolver;
use App\Service\Stats\ActivityStatsService;
use App\Service\Stats\CountryStatsService;
use App\Service\Stats\GenreStatsService;
use App\Service\Stats\OverviewStatsService;
use App\Service\Stats\PersonStatsService;
use App\Service\Stats\RatingStatsService;
use App\Service\Stats\ReleaseWindowStatsService;
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
    public function directors(Request $request, PersonStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getStats(
            $this->profileResolver->getViewedUser(),
            CreditRole::DIRECTOR,
            $this->limitFrom($request),
        ));
    }

    #[Route('/actors', methods: ['GET'])]
    public function actors(Request $request, PersonStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getStats(
            $this->profileResolver->getViewedUser(),
            CreditRole::ACTOR,
            $this->limitFrom($request),
        ));
    }

    #[Route('/writers', methods: ['GET'])]
    public function writers(Request $request, PersonStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getStats(
            $this->profileResolver->getViewedUser(),
            CreditRole::WRITER,
            $this->limitFrom($request),
        ));
    }

    #[Route('/countries', methods: ['GET'])]
    public function countries(Request $request, CountryStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getCountryStats(
            $this->profileResolver->getViewedUser(),
            $this->limitFrom($request, 12),
        ));
    }

    #[Route('/activity', methods: ['GET'])]
    public function activity(ActivityStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getActivity($this->profileResolver->getViewedUser()));
    }

    #[Route('/at-release', methods: ['GET'])]
    public function atRelease(ReleaseWindowStatsService $service): JsonResponse
    {
        return new JsonResponse($service->getReleaseWindowStats($this->profileResolver->getViewedUser()));
    }

    private function limitFrom(Request $request, int $default = 25): int
    {
        return min(100, max(1, (int) $request->query->get('limit', $default)));
    }
}
