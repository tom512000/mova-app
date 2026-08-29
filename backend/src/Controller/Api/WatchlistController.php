<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\WatchlistSearchCriteria;
use App\Entity\Enum\WatchlistSortField;
use App\Mapper\MovieMapper;
use App\Repository\WatchlistEntryRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/watchlist')]
final class WatchlistController
{
    /** Nothing on Letterboxd runs longer than this; anything above is a typed mistake. */
    private const MAX_RUNTIME_CEILING = 1000;

    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly WatchlistEntryRepository $watchlistEntryRepository,
        private readonly MovieMapper $mapper,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();
        $criteria = $this->criteriaFrom($request);

        $result = $this->watchlistEntryRepository->search($viewedUser, $criteria);

        return new JsonResponse([
            'items' => array_map(
                fn ($movie) => $this->mapper->toSummaryDto($movie, $viewedUser),
                $result['items']
            ),
            'total' => $result['total'],
            'page' => $criteria->page,
            'perPage' => $criteria->perPage,
        ]);
    }

    #[Route('/facets', methods: ['GET'])]
    public function facets(): JsonResponse
    {
        return new JsonResponse($this->watchlistEntryRepository->facets($this->profileResolver->getViewedUser()));
    }

    /**
     * "Choose for me": one entry among those the current filters keep.
     *
     * Drawn on the server rather than in the browser because the browser only holds the page
     * it is looking at — picking from twenty-four of two hundred films would quietly bias the
     * answer towards whatever the sort put first.
     */
    #[Route('/pick', methods: ['GET'])]
    public function pick(Request $request): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();
        $movie = $this->watchlistEntryRepository->pickOne($viewedUser, $this->criteriaFrom($request));

        return new JsonResponse([
            'movie' => null !== $movie ? $this->mapper->toSummaryDto($movie, $viewedUser) : null,
        ]);
    }

    private function criteriaFrom(Request $request): WatchlistSearchCriteria
    {
        $maxRuntime = $request->query->get('maxRuntime');
        $decade = $request->query->get('decade');
        $sort = WatchlistSortField::tryFrom((string) $request->query->get('sort', '')) ?? WatchlistSortField::ADDED;

        return new WatchlistSearchCriteria(
            query: $request->query->get('q'),
            maxRuntime: null !== $maxRuntime && '' !== $maxRuntime
                ? min(self::MAX_RUNTIME_CEILING, max(1, (int) $maxRuntime))
                : null,
            genre: $request->query->get('genre'),
            decade: null !== $decade && '' !== $decade ? (int) $decade : null,
            sort: $sort,
            // The direction is spelled out in the URL so that a shared link means the same
            // thing to whoever opens it, but each sort has an order that makes sense first.
            descending: match ($request->query->get('direction')) {
                'asc' => false,
                'desc' => true,
                default => $sort->defaultsToDescending(),
            },
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('perPage', 24))),
        );
    }
}
