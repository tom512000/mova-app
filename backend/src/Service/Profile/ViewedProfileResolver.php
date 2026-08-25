<?php

declare(strict_types=1);

namespace App\Service\Profile;

use App\Entity\User;
use App\Repository\ProfileAccessRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The single place that decides *whose* data a read endpoint returns.
 *
 * The SPA sends `?profileId=` (mirrored into every request by an axios interceptor) when
 * the user is looking at somebody else's profile; absent it, reads target the logged-in
 * account. Keeping the decision in one service rather than in each controller means there
 * is exactly one line to audit for "can this person see this data", and adding a read
 * endpoint can't accidentally skip the check — it has to ask this class for a User.
 *
 * Writes never come through here. Import and sync always act on getAuthenticatedUser(),
 * so a granted profile stays strictly read-only even if a client forges profileId.
 */
final class ViewedProfileResolver
{
    public const PROFILE_QUERY_PARAM = 'profileId';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
        private readonly ProfileAccessRepository $profileAccessRepository,
    ) {
    }

    /**
     * The account performing the request. Everything that writes must use this.
     */
    public function getAuthenticatedUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Authentification requise.');
        }

        return $user;
    }

    /**
     * The account whose data should be read: the caller, or a profile explicitly shared
     * with them.
     *
     * @throws AccessDeniedHttpException when profileId names a profile that was never shared
     */
    public function getViewedUser(): User
    {
        $me = $this->getAuthenticatedUser();

        $request = $this->requestStack->getCurrentRequest();
        $profileId = $request?->query->get(self::PROFILE_QUERY_PARAM);
        if (null === $profileId || '' === $profileId) {
            return $me;
        }

        if (!ctype_digit((string) $profileId)) {
            throw new NotFoundHttpException('Profil introuvable.');
        }

        $owner = $this->userRepository->find((int) $profileId);
        if (null === $owner) {
            throw new NotFoundHttpException('Profil introuvable.');
        }

        if ($owner->getId() === $me->getId()) {
            return $me;
        }

        if (!$this->profileAccessRepository->existsForPair($owner, $me)) {
            // Deliberately the same message whether the profile exists or not, so probing
            // ids can't be used to enumerate accounts.
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à ce profil.');
        }

        return $owner;
    }

    /**
     * True when the request is looking at somebody else's profile — used by endpoints that
     * exist only for the owner (import history, sync status).
     */
    public function isViewingOtherProfile(): bool
    {
        return $this->getViewedUser()->getId() !== $this->getAuthenticatedUser()->getId();
    }
}
