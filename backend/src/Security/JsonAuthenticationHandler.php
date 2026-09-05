<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Mapper\UserMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
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
        // Only when the caller already had a session, which is the only case where there is
        // a stale error to clear. Calling getSession() unconditionally starts one, and with
        // the session store in Postgres that means a written row for every failed login —
        // handing anyone who wants it a way to grow the table one wrong password at a time.
        if ($request->hasPreviousSession()) {
            $request->getSession()->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        }

        // Throttling is worth saying out loud. It reveals nothing about whether the account
        // exists — the limiter counts the attempt either way — and answering "wrong
        // password" to someone who is simply rate-limited would send an honest person back
        // to check a password that was never the problem.
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new JsonResponse(
                ['message' => 'Trop de tentatives de connexion. Réessaie dans quelques minutes.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // Never echo $exception->getMessage(): it distinguishes "unknown email" from
        // "wrong password", which turns the login form into an account-enumeration oracle.
        return new JsonResponse(['message' => 'Email ou mot de passe incorrect.'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
    }
}
