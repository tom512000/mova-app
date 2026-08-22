<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

use App\Exception\TmdbException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the TMDB v3 REST API: https://developer.themoviedb.org/reference.
 * Only fetches the fields this app actually uses (via append_to_response) rather than
 * pulling every available TMDB field.
 */
final class TmdbClient implements TmdbClientInterface
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.tmdb.api_key%')]
        private readonly string $apiKey,
        #[Autowire('%app.tmdb.api_base_url%')]
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id: int, title: string, original_title: string, release_date: string, popularity: float}>
     */
    public function searchMovie(string $query, ?int $year): array
    {
        $params = ['query' => $query];
        if (null !== $year) {
            $params['year'] = $year;
        }

        return $this->request('GET', '/search/movie', $params)['results'] ?? [];
    }

    /**
     * @return array<string, mixed> movie details with credits and external_ids appended
     */
    public function getMovieDetails(int $tmdbId): array
    {
        return $this->request('GET', "/movie/{$tmdbId}", ['append_to_response' => 'credits,external_ids']);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        if ('' === $this->apiKey) {
            throw new TmdbException('TMDB_API_KEY non configurée.');
        }

        $query['api_key'] = $this->apiKey;
        $query['language'] = 'fr-FR';

        $attempt = 0;
        while (true) {
            ++$attempt;

            try {
                $response = $this->httpClient->request($method, $this->baseUrl.$path, ['query' => $query]);
                $statusCode = $response->getStatusCode();

                if (429 === $statusCode) {
                    $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 1);
                    if ($attempt > self::MAX_RETRIES) {
                        throw new TmdbException('Limite de requêtes TMDB dépassée (429) après plusieurs tentatives.');
                    }
                    $this->logger->warning('TMDB rate limited, retrying in {seconds}s', ['seconds' => $retryAfter]);
                    sleep(max(1, $retryAfter));
                    continue;
                }

                if ($statusCode >= 500 && $attempt <= self::MAX_RETRIES) {
                    $this->logger->warning('TMDB server error {status}, retrying (attempt {attempt})', ['status' => $statusCode, 'attempt' => $attempt]);
                    sleep($attempt);
                    continue;
                }

                if ($statusCode >= 400) {
                    throw new TmdbException(sprintf('Erreur TMDB %d sur %s', $statusCode, $path));
                }

                return $response->toArray();
            } catch (HttpClientExceptionInterface $e) {
                if ($attempt > self::MAX_RETRIES) {
                    throw new TmdbException('Échec de connexion à TMDB : '.$e->getMessage(), previous: $e);
                }
                $this->logger->warning('TMDB connection error, retrying (attempt {attempt}): {message}', ['attempt' => $attempt, 'message' => $e->getMessage()]);
                sleep($attempt);
            }
        }
    }
}
