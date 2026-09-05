<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Is this container able to answer? Nothing more.
 *
 * Public, and the only /api route that is, so a container healthcheck does not have to
 * authenticate to prove the process is alive. That matters more than it sounds: a probe
 * against a protected route makes Symfony stash a post-login redirect target in the session
 * before answering 401, and PdoSessionHandler then writes a row for it. Every thirty
 * seconds, forever.
 *
 * Deliberately does not touch the database. Postgres has a healthcheck of its own, and
 * tying this one to it would restart a perfectly good web container over a blip on a
 * dependency it does not own.
 */
final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
