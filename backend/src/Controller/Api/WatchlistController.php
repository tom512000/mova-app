<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Mapper\MovieMapper;
use App\Repository\WatchlistEntryRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/watchlist')]
final class WatchlistController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, WatchlistEntryRepository $watchlistEntryRepository, MovieMapper $mapper): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', 24)));

        $result = $watchlistEntryRepository->search(
            user: $viewedUser,
            query: $request->query->get('q'),
            page: $page,
            perPage: $perPage,
        );

        return new JsonResponse([
            'items' => array_map(
                static fn ($movie) => $mapper->toSummaryDto($movie, $viewedUser),
                $result['items']
            ),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }
}
