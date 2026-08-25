<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Mapper\MovieMapper;
use App\Repository\MovieRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/movies')]
final class MovieController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', 24)));

        $result = $movieRepository->search(
            user: $viewedUser,
            query: $request->query->get('q'),
            genre: $request->query->get('genre'),
            year: $request->query->has('year') ? (int) $request->query->get('year') : null,
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

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();

        $movie = $movieRepository->find($id);
        if (null === $movie) {
            return new JsonResponse(['error' => 'Film introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($mapper->toDetailDto($movie, $viewedUser));
    }
}
