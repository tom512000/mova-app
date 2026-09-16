<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\PersonSearchCriteria;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\PersonSortField;
use App\Entity\Person;
use App\Mapper\PersonMapper;
use App\Repository\PersonRepository;
use App\Service\Person\PersonFilmographyService;
use App\Service\Person\PersonProfileService;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The people: the directory, and one person's page.
 *
 * The directory is the library read down its other axis. The films listing answers "what
 * have I seen"; this answers "who have I been watching", which until now could only be
 * guessed at from the four rankings on the dashboard — the top twenty-five of four jobs,
 * with no way to search them, page them or ask about a fifth.
 *
 * The page below it is split in two along where the data comes from. The profile is
 * answered from the library and returns in a few milliseconds; the filmography needs a TMDB
 * round trip on a cold cache. Behind one endpoint the whole page would wait on the slower
 * half every time.
 *
 * Read-only throughout, so everything here reports on the *viewed* profile — a shared
 * profile's people are that profile's people, not the caller's.
 */
#[Route('/api/people')]
final class PersonController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly PersonRepository $personRepository,
        private readonly PersonProfileService $profileService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, PersonMapper $mapper): JsonResponse
    {
        $criteria = $this->criteriaFrom($request);
        $result = $this->personRepository->search($this->profileResolver->getViewedUser(), $criteria);

        return new JsonResponse([
            'items' => array_map($mapper->toSummaryDto(...), $result['items']),
            'total' => $result['total'],
            'page' => $criteria->page,
            'perPage' => $criteria->perPage,
        ]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $person = $this->findPerson($id);
        if (null === $person) {
            return new JsonResponse(['error' => 'Personne introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->profileService->getProfile($person, $this->profileResolver->getViewedUser()));
    }

    /**
     * Returns null rather than 404 when there is nothing to show — no TMDB id, TMDB
     * unreachable, nothing surviving the filters. The section simply does not draw, and the
     * page is not made to look broken over a part of it that was always optional.
     */
    #[Route('/{id}/filmography', methods: ['GET'])]
    public function filmography(string $id, PersonFilmographyService $filmographyService): JsonResponse
    {
        $person = $this->findPerson($id);
        if (null === $person) {
            return new JsonResponse(['error' => 'Personne introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->profileResolver->getViewedUser();

        // The profile is rebuilt here only for its role ordering, so the filmography lists
        // jobs in the same order the page above it does. Two indexed queries against a
        // request that is about to hit the network anyway.
        $roles = array_map(
            static fn ($role) => $role->role,
            $this->profileService->getProfile($person, $user)->roles
        );

        return new JsonResponse($filmographyService->getFilmography($person, $user, $roles));
    }

    /**
     * Unknown values fall back to the default rather than raising a 422: these arrive from
     * the address bar, and a stale bookmark should still show the directory.
     */
    private function criteriaFrom(Request $request): PersonSearchCriteria
    {
        $query = $request->query;

        // Most-watched first, where the films listing opens alphabetically. A library holds
        // a few hundred titles and several thousand names, nearly all of them a face in a
        // crowd scene — opening on the As would be opening on the people this profile has
        // the least to do with.
        $sort = PersonSortField::tryFrom((string) $query->get('sort', '')) ?? PersonSortField::WORKS;
        $direction = $query->get('direction');
        $descending = match ($direction) {
            'asc' => false,
            'desc' => true,
            default => $sort->defaultsToDescending(),
        };

        return new PersonSearchCriteria(
            query: $this->trimmedOrNull($query->get('q')),
            role: CreditRole::tryFrom((string) $query->get('role', '')),
            // Absent or unrecognised means the whole library, films and series together —
            // the same forgiving reading every other filter here gets.
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
     * Anything that is not a UUID is treated as an unknown person rather than a bad
     * request: these ids arrive from the address bar, and a mistyped one should meet the
     * page's own "introuvable" rather than a validation error.
     */
    private function findPerson(string $id): ?Person
    {
        return Uuid::isValid($id) ? $this->personRepository->find($id) : null;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
