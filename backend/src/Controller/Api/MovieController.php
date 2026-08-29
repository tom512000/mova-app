<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\MovieSearchCriteria;
use App\DTO\PersonFilterDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\MovieSortField;
use App\Mapper\MovieMapper;
use App\Repository\MovieRepository;
use App\Repository\PersonRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/movies')]
final class MovieController
{
    /** The value the rating filter takes to mean "films I never rated". */
    private const UNRATED = 'none';

    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(
        Request $request,
        MovieRepository $movieRepository,
        PersonRepository $personRepository,
        MovieMapper $mapper,
    ): JsonResponse {
        $viewedUser = $this->profileResolver->getViewedUser();
        $criteria = $this->criteriaFrom($request);

        $result = $movieRepository->search($viewedUser, $criteria);

        return new JsonResponse([
            'items' => array_map(
                static fn ($movie) => $mapper->toSummaryDto($movie, $viewedUser),
                $result['items']
            ),
            'total' => $result['total'],
            'page' => $criteria->page,
            'perPage' => $criteria->perPage,
            'person' => $this->resolvePerson($criteria, $personRepository),
        ]);
    }

    /**
     * Feeds the filter dropdowns. Separate from the listing because it only changes when
     * the library does, so the client can cache it across every filter change.
     */
    /**
     * The museum wall: every poster the profile owns, in one response. Unpaged on purpose —
     * the wall is one continuous surface, and a page boundary in the middle of it would be
     * a wall you cannot walk past.
     */
    #[Route('/posters', methods: ['GET'])]
    public function posters(Request $request, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $criteria = $this->criteriaFrom($request);
        $rows = $movieRepository->posterWall($this->profileResolver->getViewedUser(), $criteria);

        return new JsonResponse([
            'items' => array_map($mapper->toPosterDto(...), $rows),
            'total' => \count($rows),
        ]);
    }

    #[Route('/facets', methods: ['GET'])]
    public function facets(MovieRepository $movieRepository): JsonResponse
    {
        return new JsonResponse($movieRepository->facetsFor($this->profileResolver->getViewedUser()));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function show(string $id, MovieRepository $movieRepository, MovieMapper $mapper): JsonResponse
    {
        $viewedUser = $this->profileResolver->getViewedUser();

        $movie = $movieRepository->find($id);
        if (null === $movie) {
            return new JsonResponse(['error' => 'Film introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($mapper->toDetailDto($movie, $viewedUser));
    }

    /**
     * Unknown values fall back to the default rather than raising a 422: these arrive from
     * the address bar, and a stale bookmark should still show the library.
     */
    private function criteriaFrom(Request $request): MovieSearchCriteria
    {
        $query = $request->query;

        $sort = MovieSortField::tryFrom((string) $query->get('sort', '')) ?? MovieSortField::TITLE;
        $direction = $query->get('direction');
        $descending = match ($direction) {
            'asc' => false,
            'desc' => true,
            default => $sort->defaultsToDescending(),
        };

        $rating = (string) $query->get('rating', '');
        $unratedOnly = self::UNRATED === $rating;

        return new MovieSearchCriteria(
            query: $this->trimmedOrNull($query->get('q')),
            genre: $this->trimmedOrNull($query->get('genre')),
            year: $query->has('year') && '' !== $query->get('year') ? (int) $query->get('year') : null,
            rating: !$unratedOnly && '' !== $rating ? $this->clampRating((float) $rating) : null,
            unratedOnly: $unratedOnly,
            // Only a well-formed UUID reaches the query; anything else is read as no
            // filter at all, the same forgiving treatment the other parameters get.
            personId: $this->uuidOrNull($query->get('personId')),
            personRole: CreditRole::tryFrom((string) $query->get('personRole', '')),
            // Absent or unrecognised means the whole library, films and series together —
            // the same forgiving reading as every other filter here.
            mediaType: MediaType::tryFrom((string) $query->get('mediaType', '')),
            sort: $sort,
            descending: $descending,
            // Only alphanumerics reach the SQL, though it is a bound parameter either way.
            seed: substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $query->get('seed', '')) ?? '', 0, 32),
            page: max(1, (int) $query->get('page', 1)),
            perPage: min(100, max(1, (int) $query->get('perPage', 24))),
        );
    }

    /**
     * The client filters on an id but has to label the filter with a name, so the listing
     * hands it back rather than making it fetch the person separately.
     */
    private function resolvePerson(MovieSearchCriteria $criteria, PersonRepository $personRepository): ?PersonFilterDto
    {
        if (null === $criteria->personId) {
            return null;
        }

        $person = $personRepository->find($criteria->personId);

        return null === $person
            ? null
            : new PersonFilterDto((string) $person->getId(), $person->getName(), $criteria->personRole);
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return \is_string($value) && Uuid::isValid($value) ? $value : null;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /** Snaps to the half-star scale so a hand-edited URL cannot ask for a note nobody gives. */
    private function clampRating(float $rating): ?float
    {
        $snapped = round($rating * 2) / 2;

        return $snapped >= 0.5 && $snapped <= 5.0 ? $snapped : null;
    }
}
