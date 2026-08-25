<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps /api answering JSON when something throws. Without this Symfony renders its HTML
 * error page (the full debug page in dev), which an axios client can only report as an
 * opaque failure — the actual "you don't have access to this profile" never reaches the UI.
 *
 * Priority 0 puts it *after* the security ExceptionListener (priority 1) on purpose. That
 * listener is what turns "anonymous hit a protected route" into a 401 through the firewall's
 * entry point; jumping ahead of it would answer 403 instead, and the SPA keys its redirect
 * to the login page off the 401. Whatever security already answered is left alone.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly bool $debug,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        // A 500 is the one case where the message is ours rather than a deliberate API
        // answer, so it can carry internals (SQL, paths). Only expose it with debug on.
        $message = Response::HTTP_INTERNAL_SERVER_ERROR === $statusCode && !$this->debug
            ? 'Une erreur interne est survenue.'
            : $exception->getMessage();

        $event->setResponse(new JsonResponse(['error' => $message], $statusCode));
    }
}
