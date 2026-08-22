<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fallback used only when the TMDB search API can't confidently resolve a film
 * (see TmdbResolver) — fetches the film's *public* Letterboxd page and reads the
 * TMDB/IMDb links Letterboxd itself displays there. Deliberately narrow in scope:
 * one request per unique film slug ever needed, result cached permanently, never
 * used for the user's personal activity (diary/ratings/watchlist stay CSV/RSS-only).
 */
final class LetterboxdFilmPageResolver implements LetterboxdFilmPageResolverInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{tmdbId: int|null, imdbId: string|null}
     */
    public function resolve(string $slug): array
    {
        return $this->cache->get('letterboxd_film_page.'.$slug, function (ItemInterface $item) use ($slug): array {
            $item->expiresAfter(null); // permanent: a film's TMDB/IMDb id never changes

            return $this->fetch($slug);
        });
    }

    /**
     * @return array{tmdbId: int|null, imdbId: string|null}
     */
    private function fetch(string $slug): array
    {
        try {
            $response = $this->httpClient->request('GET', "https://letterboxd.com/film/{$slug}/");
            if (200 !== $response->getStatusCode()) {
                return ['tmdbId' => null, 'imdbId' => null];
            }

            $html = $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Could not fetch Letterboxd film page for "{slug}": {message}', ['slug' => $slug, 'message' => $e->getMessage()]);

            return ['tmdbId' => null, 'imdbId' => null];
        }

        $tmdbId = null;
        if (preg_match('#themoviedb\.org/movie/(\d+)#', $html, $matches)) {
            $tmdbId = (int) $matches[1];
        }

        $imdbId = null;
        if (preg_match('#imdb\.com/title/(tt\d+)#', $html, $matches)) {
            $imdbId = $matches[1];
        }

        return ['tmdbId' => $tmdbId, 'imdbId' => $imdbId];
    }
}
