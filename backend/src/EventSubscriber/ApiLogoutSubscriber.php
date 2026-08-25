<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Without a response set here the logout listener falls back to a redirect, and the SPA's
 * axios call follows the 302 into an HTML page on the API origin — which then fails CORS
 * and surfaces as a network error even though the session was destroyed correctly.
 */
final class ApiLogoutSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(null, Response::HTTP_NO_CONTENT));
    }
}
