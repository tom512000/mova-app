<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the API out of search results.
 *
 * There is a trap here worth naming, because it makes the problem invisible during
 * development: Symfony's own DisallowRobotsIndexingListener already sets this header — but
 * only when kernel.debug is true. So every API response carries `X-Robots-Tag: noindex`
 * locally and none of them would in production, which is the one environment where it
 * matters. This subscriber is the version that ships.
 *
 * robots.txt is not a substitute. It stops a crawler fetching a URL; it does not stop the URL
 * being listed when something else links to it. A header travels with the response and says
 * "do not index this", which is the thing actually wanted for a JSON endpoint that may hold
 * somebody's viewing history.
 *
 * `noarchive` is in there too: an indexable endpoint is one problem, a cached copy of one
 * living on somebody else's servers is another.
 */
final class ApiRobotsSubscriber implements EventSubscriberInterface
{
    private const DIRECTIVES = 'noindex, nofollow, noarchive';

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', self::DIRECTIVES);
    }
}
