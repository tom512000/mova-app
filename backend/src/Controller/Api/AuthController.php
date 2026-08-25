<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Mapper\UserMapper;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly UserMapper $userMapper,
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

    /**
     * How the SPA finds out on boot whether it has a live session — a 401 here is what
     * sends it to the login page.
     */
    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return new JsonResponse($this->userMapper->toDto($this->profileResolver->getAuthenticatedUser()));
    }
}
