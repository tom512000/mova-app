<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Auth\ChangePasswordRequest;
use App\DTO\Auth\RegisterRequest;
use App\Entity\User;
use App\Mapper\UserMapper;
use App\Repository\UserRepository;
use App\Service\Profile\ViewedProfileResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly UserMapper $userMapper,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * Never actually executed: the firewall's json_login listener intercepts POSTs to this
     * path and answers through JsonAuthenticationHandler. The route has to exist anyway so
     * check_path can resolve it by name.
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Same deal: the firewall's logout listener owns this path.
     */
    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        // Emails are compared case-insensitively by every mail system, so normalising here
        // stops "Tom@x.com" and "tom@x.com" becoming two accounts that look identical.
        $email = mb_strtolower(trim($request->email));

        if (null !== $this->userRepository->findOneByEmail($email)) {
            // 409 rather than a field violation: it is the one error the client can't
            // predict from the input alone.
            return new JsonResponse(
                ['error' => 'Un compte existe déjà avec cet email.'],
                Response::HTTP_CONFLICT
            );
        }

        $user = new User($email, trim($request->displayName));
        $user->setPassword($this->passwordHasher->hashPassword($user, $request->password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Signing in straight away spares the new account an immediate second form with the
        // credentials it just typed. 'api' names the firewall, 'json_login' the authenticator.
        $this->security->login($user, 'json_login', 'api');

        return new JsonResponse($this->userMapper->toDto($user), Response::HTTP_CREATED);
    }

    /**
     * How the SPA finds out on boot whether it has a live session — a 401 here is what
     * sends it to the login page.
     */
    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return new JsonResponse($this->userMapper->toDto($this->profileResolver->getAuthenticatedUser()));
    }

    #[Route('/password', name: 'api_auth_change_password', methods: ['PUT'])]
    public function changePassword(#[MapRequestPayload] ChangePasswordRequest $request): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        if (!$this->passwordHasher->isPasswordValid($user, $request->currentPassword)) {
            return new JsonResponse(
                ['error' => 'Le mot de passe actuel est incorrect.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $request->newPassword));
        $this->entityManager->flush();

        // The session survives on purpose: the token is refreshed from the user identifier
        // and roles, neither of which changed, so the person who just proved they know the
        // old password isn't kicked out of the tab they are standing in.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
