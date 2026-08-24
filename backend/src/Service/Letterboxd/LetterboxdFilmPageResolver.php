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
     * @return array{tmdbId: int|null, tmdbTvId: int|null, imdbId: string|null}
     */
    public function resolve(string $slug): array
    {
        // The version segment is part of the key on purpose: entries cached before
        // `tmdbTvId` existed have the old two-key shape, and they never expire.
        return $this->cache->get('letterboxd_film_page.v2.'.$slug, function (ItemInterface $item, bool &$save) use ($slug): array {
            $item->expiresAfter(null); // permanent: a film's TMDB/IMDb id never changes

            $result = $this->fetch($slug);

            // A miss carries no information — it's as likely to be a 429 or a dropped
            // connection as a page genuinely without a TMDB link. Caching it under the
            // permanent TTL above would make one throttled request permanent truth, which
            // is how Cast Away and two Indiana Jones ended up unresolvable forever.
            $save = null !== $result['tmdbId'] || null !== $result['tmdbTvId'];

            return $result;
        });
    }

    /**
     * @return array{tmdbId: int|null, tmdbTvId: int|null, imdbId: string|null}
     */
    private function fetch(string $slug): array
    {
        try {
            $response = $this->httpClient->request('GET', "https://letterboxd.com/film/{$slug}/");
            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $this->logger->warning('Letterboxd film page for "{slug}" returned HTTP {status}', ['slug' => $slug, 'status' => $statusCode]);

                return self::empty();
            }

            $html = $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Could not fetch Letterboxd film page for "{slug}": {message}', ['slug' => $slug, 'message' => $e->getMessage()]);

            return self::empty();
        }

        $tmdbId = null;
        $tmdbTvId = null;
        // Letterboxd links a film to either /movie/<id> or /tv/<id>; entries backed by a
        // series (mini-series, some anthologies) have no TMDB movie at all, and reporting
        // the series id as a movie id would send TmdbClient::getMovieDetails() to a
        // completely unrelated film that happens to share that number.
        if (preg_match('#themoviedb\.org/(movie|tv)/(\d+)#', $html, $matches)) {
            if ('movie' === $matches[1]) {
                $tmdbId = (int) $matches[2];
            } else {
                $tmdbTvId = (int) $matches[2];
            }
        }

        $imdbId = null;
        if (preg_match('#imdb\.com/title/(tt\d+)#', $html, $matches)) {
            $imdbId = $matches[1];
        }

        return ['tmdbId' => $tmdbId, 'tmdbTvId' => $tmdbTvId, 'imdbId' => $imdbId];
    }

    /**
     * @return array{tmdbId: null, tmdbTvId: null, imdbId: null}
     */
    private static function empty(): array
    {
        return ['tmdbId' => null, 'tmdbTvId' => null, 'imdbId' => null];
    }
}
