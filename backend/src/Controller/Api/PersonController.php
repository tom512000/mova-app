<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Person;
use App\Repository\PersonRepository;
use App\Service\Person\PersonFilmographyService;
use App\Service\Person\PersonProfileService;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * A person's page.
 *
 * Two endpoints for one page, split along where the data comes from. The profile is
 * answered from the library and returns in a few milliseconds; the filmography needs a
 * TMDB round trip on a cold cache. Behind one endpoint the whole page would wait on the
 * slower half every time.
 *
 * Read-only, so both report on the *viewed* profile — a shared profile's person page shows
 * that profile's ratings, not the caller's.
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
     * Anything that is not a UUID is treated as an unknown person rather than a bad
     * request: these ids arrive from the address bar, and a mistyped one should meet the
     * page's own "introuvable" rather than a validation error.
     */
    private function findPerson(string $id): ?Person
    {
        return Uuid::isValid($id) ? $this->personRepository->find($id) : null;
    }
}
