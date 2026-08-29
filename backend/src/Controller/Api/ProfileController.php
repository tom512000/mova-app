<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Profile\ShareLinkDto;
use App\Entity\ProfileAccess;
use App\Entity\ProfileShareLink;
use App\Mapper\LetterboxdProfileMapper;
use App\Mapper\UserMapper;
use App\Repository\LetterboxdProfileRepository;
use App\Repository\ProfileAccessRepository;
use App\Repository\ProfileShareLinkRepository;
use App\Service\Profile\ViewedProfileResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profiles')]
final class ProfileController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly ProfileShareLinkRepository $shareLinkRepository,
        private readonly ProfileAccessRepository $profileAccessRepository,
        private readonly UserMapper $userMapper,
        private readonly LetterboxdProfileRepository $letterboxdProfiles,
        private readonly LetterboxdProfileMapper $letterboxdProfileMapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Everything the header switcher needs: me first, then the profiles shared with me.
     */
    #[Route('', name: 'api_profiles_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $me = $this->profileResolver->getAuthenticatedUser();

        $profiles = [$this->userMapper->toSummaryDto($me, $me)];
        foreach ($this->profileAccessRepository->findOwnersGrantedTo($me) as $owner) {
            $profiles[] = $this->userMapper->toSummaryDto($owner, $me);
        }

        return new JsonResponse($profiles);
    }

    /**
     * What profile.csv said about my Letterboxd account, favourites included.
     *
     * getAuthenticatedUser(), not getViewedUser(): this is the account settings screen, and
     * it describes whoever is signed in — switching to a shared profile must not swap what
     * "my profile" means here.
     *
     * Null rather than 404 when nothing has been imported: having no profile.csv yet is an
     * ordinary state the screen has a panel for, not an error.
     */
    #[Route('/letterboxd', name: 'api_profiles_letterboxd', methods: ['GET'])]
    public function letterboxd(): JsonResponse
    {
        $profile = $this->letterboxdProfiles->findOneByUser($this->profileResolver->getAuthenticatedUser());

        return new JsonResponse([
            'profile' => null === $profile ? null : $this->letterboxdProfileMapper->toDto($profile),
        ]);
    }

    /**
     * Returns my share link, minting it on first call. Idempotent so the UI can show the
     * link without a separate "create" step.
     */
    #[Route('/share-link', name: 'api_profiles_share_link', methods: ['GET', 'POST'])]
    public function shareLink(): JsonResponse
    {
        $me = $this->profileResolver->getAuthenticatedUser();

        $link = $this->shareLinkRepository->findOneByOwner($me);
        if (null === $link) {
            $link = new ProfileShareLink($me);
            $this->entityManager->persist($link);
            $this->entityManager->flush();
        }

        return new JsonResponse(new ShareLinkDto($link->getToken(), $link->getCreatedAt()->format('c')));
    }

    /**
     * Invalidates the old link and returns a fresh one. Existing grants survive — see
     * ProfileShareLink::rotate().
     */
    #[Route('/share-link/rotate', name: 'api_profiles_share_link_rotate', methods: ['POST'])]
    public function rotateShareLink(): JsonResponse
    {
        $me = $this->profileResolver->getAuthenticatedUser();

        $link = $this->shareLinkRepository->findOneByOwner($me);
        if (null === $link) {
            $link = new ProfileShareLink($me);
            $this->entityManager->persist($link);
        } else {
            $link->rotate();
        }
        $this->entityManager->flush();

        return new JsonResponse(new ShareLinkDto($link->getToken(), $link->getCreatedAt()->format('c')));
    }

    /**
     * What the recipient's browser hits when they open a share link. Idempotent: opening
     * the same link twice reports the same grant rather than failing on the unique pair.
     */
    #[Route('/share-link/{token}/accept', name: 'api_profiles_share_accept', methods: ['POST'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function acceptShareLink(string $token): JsonResponse
    {
        $me = $this->profileResolver->getAuthenticatedUser();

        $link = $this->shareLinkRepository->findOneByToken($token);
        if (null === $link) {
            return new JsonResponse(['error' => 'Ce lien de partage est invalide ou a été révoqué.'], Response::HTTP_NOT_FOUND);
        }

        $owner = $link->getOwner();
        if ($owner->getId()->equals($me->getId())) {
            return new JsonResponse(['error' => 'Ce lien est le vôtre.'], Response::HTTP_CONFLICT);
        }

        $alreadyGranted = $this->profileAccessRepository->existsForPair($owner, $me);
        if (!$alreadyGranted) {
            $this->entityManager->persist(new ProfileAccess($owner, $me));
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'profile' => $this->userMapper->toSummaryDto($owner, $me),
            'alreadyGranted' => $alreadyGranted,
        ]);
    }

    /**
     * Lets a viewer drop a profile from their own switcher.
     */
    #[Route('/{id}/access', name: 'api_profiles_revoke_access', methods: ['DELETE'], requirements: ['id' => Requirement::UUID_V7])]
    public function revokeAccess(string $id): JsonResponse
    {
        $me = $this->profileResolver->getAuthenticatedUser();

        foreach ($this->profileAccessRepository->findOwnersGrantedTo($me) as $owner) {
            if ((string) $owner->getId() !== $id) {
                continue;
            }

            $access = $this->profileAccessRepository->findOneByPair($owner, $me);
            if (null !== $access) {
                $this->entityManager->remove($access);
                $this->entityManager->flush();
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse(['error' => 'Profil introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
