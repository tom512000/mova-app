<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Mapper\MovieMapper;
use App\Repository\WatchlistEntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/watchlist')]
final class WatchlistController
{
    #[Route('', methods: ['GET'])]
    public function list(Request $request, WatchlistEntryRepository $watchlistEntryRepository, MovieMapper $mapper): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', 24)));

        $result = $watchlistEntryRepository->search(
            query: $request->query->get('q'),
            page: $page,
            perPage: $perPage,
        );

        return new JsonResponse([
            'items' => array_map($mapper->toSummaryDto(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }
}
