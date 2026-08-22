<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Mapper\MovieMapper;
use App\Repository\MovieRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/movies')]
final class MovieController
{
    #[Route('', methods: ['GET'])]
    public function list(Request $request, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', 24)));

        $result = $movieRepository->search(
            query: $request->query->get('q'),
            genre: $request->query->get('genre'),
            year: $request->query->has('year') ? (int) $request->query->get('year') : null,
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

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $movie = $movieRepository->find($id);
        if (null === $movie) {
            return new JsonResponse(['error' => 'Film introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($mapper->toDetailDto($movie));
    }
}
