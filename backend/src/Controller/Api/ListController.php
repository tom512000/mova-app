<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\ListEntrySearchCriteria;
use App\DTO\MovieList\MovieListDetailDto;
use App\DTO\MovieList\MovieListEntryDto;
use App\DTO\MovieList\MovieListSummaryDto;
use App\Entity\Enum\ListEntrySortField;
use App\Entity\Enum\MediaType;
use App\Mapper\MovieMapper;
use App\Repository\MovieListRepository;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The profile's Letterboxd lists. Read-only, like every listing: lists arrive through the
 * import, and every route resolves the *viewed* profile, so a shared profile's lists can be
 * browsed — with its owner's progress through them, as every other page of a shared
 * profile shows its owner's ratings.
 */
#[Route('/api/lists')]
final class ListController
{
    /** Posters on an index card: enough to fan out, few enough to stay a cover. */
    private const COVER_POSTERS = 5;

    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly MovieListRepository $lists,
        private readonly MovieMapper $mapper,
    ) {
    }

    /**
     * Unpaged: an account holds tens of lists, not thousands, and the index is a wall of
     * covers to scan rather than a table to page through.
     */
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();
        $covers = $this->lists->coverPosters($user, self::COVER_POSTERS);

        return new JsonResponse(array_map(
            fn (array $row) => new MovieListSummaryDto(
                id: (string) $row['id'],
                name: (string) $row['name'],
                description: $row['description'],
                tags: $this->decodeTags($row['tags']),
                letterboxdUrl: (string) $row['letterboxd_url'],
                createdOn: $row['created_on'],
                workCount: (int) $row['work_count'],
                watchedCount: (int) $row['watched_count'],
                posterUrls: array_values(array_filter(array_map(
                    $this->mapper->posterUrl(...),
                    $covers[(string) $row['id']] ?? []
                ))),
            ),
            $this->lists->summaries($user)
        ));
    }

    /**
     * The list's header, split from its films like the person page is split from their
     * filmography: this one is a handful of aggregates, and the grid below re-queries on
     * every filter change while the header has no reason to.
     */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function show(string $id): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();
        $list = $this->lists->findOneOwnedBy($user, $id);
        if (null === $list) {
            return new JsonResponse(['error' => 'Liste introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $tallies = $this->lists->tallies($list, $user);

        return new JsonResponse(new MovieListDetailDto(
            id: (string) $list->getId(),
            name: $list->getName(),
            description: $list->getDescription(),
            tags: $list->getTags(),
            letterboxdUrl: $list->getLetterboxdUrl(),
            createdOn: $list->getCreatedOn()?->format('Y-m-d'),
            importedAt: $list->getImportedAt()->format(\DateTimeInterface::ATOM),
            workCount: $tallies['work_count'],
            movieCount: $tallies['movie_count'],
            seriesCount: $tallies['series_count'],
            watchedCount: $tallies['watched_count'],
            remainingRuntimeMinutes: $tallies['remaining_runtime'],
            remainingWithoutRuntime: $tallies['remaining_without_runtime'],
            averageRating: null !== $tallies['average_rating'] ? round((float) $tallies['average_rating'], 2) : null,
            genres: $this->lists->genresOf($list),
        ));
    }

    #[Route('/{id}/entries', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function entries(string $id, Request $request): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();
        $list = $this->lists->findOneOwnedBy($user, $id);
        if (null === $list) {
            return new JsonResponse(['error' => 'Liste introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $criteria = $this->criteriaFrom($request);
        $result = $this->lists->searchEntries($list, $user, $criteria);

        return new JsonResponse([
            'items' => array_map(
                fn ($entry) => new MovieListEntryDto(
                    position: $entry->getPosition(),
                    note: $entry->getNote(),
                    movie: $this->mapper->toSummaryDto($entry->getMovie(), $user),
                ),
                $result['items']
            ),
            'total' => $result['total'],
            'page' => $criteria->page,
            'perPage' => $criteria->perPage,
        ]);
    }

    /**
     * Unknown values fall back to the default rather than raising a 422, as on every other
     * listing: these arrive from the address bar, and a stale bookmark should still open.
     */
    private function criteriaFrom(Request $request): ListEntrySearchCriteria
    {
        $query = $request->query;

        $sort = ListEntrySortField::tryFrom((string) $query->get('sort', '')) ?? ListEntrySortField::POSITION;
        $descending = match ($query->get('direction')) {
            'asc' => false,
            'desc' => true,
            default => $sort->defaultsToDescending(),
        };

        $q = trim((string) $query->get('q', ''));
        $genre = trim((string) $query->get('genre', ''));

        return new ListEntrySearchCriteria(
            query: '' === $q ? null : $q,
            watched: match ($query->get('status')) {
                'watched' => true,
                'unwatched' => false,
                default => null,
            },
            mediaType: MediaType::tryFrom((string) $query->get('mediaType', '')),
            genre: '' === $genre ? null : $genre,
            sort: $sort,
            descending: $descending,
            // Only alphanumerics reach the SQL, though it is a bound parameter either way.
            seed: substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $query->get('seed', '')) ?? '', 0, 32),
            page: max(1, (int) $query->get('page', 1)),
            perPage: min(100, max(1, (int) $query->get('perPage', 24))),
        );
    }

    /**
     * @return list<string>
     */
    private function decodeTags(mixed $json): array
    {
        $tags = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($tags) ? array_values(array_map('strval', $tags)) : [];
    }
}
