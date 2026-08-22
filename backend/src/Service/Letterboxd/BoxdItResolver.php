<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Letterboxd's CSV export gives every film a `https://boxd.it/<code>` short link
 * instead of the full film URL (confirmed against a real export — the docs don't
 * mention this). It's an official Letterboxd redirector (Cloudflare-fronted,
 * `x-letterboxd-type` response header) that 302s straight to the public film page
 * in a single hop, so resolving it is one lightweight request per unique film,
 * cached permanently by code — never re-resolved once known.
 */
final class BoxdItResolver implements BoxdItResolverInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $code): ?string
    {
        return $this->cache->get('boxdit.'.$code, function (ItemInterface $item) use ($code): ?string {
            $item->expiresAfter(null); // a boxd.it code's target film never changes

            return $this->fetchLocation($code);
        });
    }

    private function fetchLocation(string $code): ?string
    {
        try {
            $response = $this->httpClient->request('GET', "https://boxd.it/{$code}", [
                'max_redirects' => 0,
            ]);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 300 || $statusCode >= 400) {
                return null;
            }

            $location = $response->getHeaders(false)['location'][0] ?? null;

            return $location ?: null;
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Could not resolve boxd.it code "{code}": {message}', ['code' => $code, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
