<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Mapper\UserMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Keeps the firewall speaking JSON in all three directions: a successful login returns the
 * account, a failed one returns 401 with a message, and an anonymous request to a protected
 * route returns 401 instead of the default redirect (which a fetch/axios client would follow
 * into an HTML page and report as a confusing CORS error).
 */
final class JsonAuthenticationHandler implements
    AuthenticationSuccessHandlerInterface,
    AuthenticationFailureHandlerInterface,
    AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UserMapper $userMapper,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, $token): Response
    {
        $user = $token->getUser();

        return new JsonResponse($user instanceof User ? $this->userMapper->toDto($user) : null);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);

        // Never echo $exception->getMessage(): it distinguishes "unknown email" from
        // "wrong password", which turns the login form into an account-enumeration oracle.
        return new JsonResponse(['message' => 'Email ou mot de passe incorrect.'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
    }
}
