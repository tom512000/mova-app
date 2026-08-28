<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

use App\Exception\TmdbException;
use Psr\Log\LoggerInterface;

/**
 * How long a series actually is, in minutes.
 *
 * TMDB exposes `episode_run_time` on /tv/{id} but has largely stopped populating it —
 * empty on 13 of the 16 series in this library, Loki and Squid Game included. The real
 * figures only exist per episode, which means one /tv/{id}/season/{n} call per season
 * and adding them up: Loki comes to 615 minutes over twelve episodes and two calls.
 *
 * Specials (season 0) are skipped so the total stays consistent with the episode count
 * TMDB reports, which excludes them too.
 */
final class SeriesRuntimeResolver
{
    public function __construct(
        private readonly TmdbClientInterface $tmdbClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $details a /tv/{id} response, for its `seasons` list
     *
     * @return int|null null when no episode carries a runtime, or when TMDB could not be
     *                  reached for a season — a series is worth keeping without its duration,
     *                  so this never throws
     */
    public function totalMinutes(int $tvId, array $details): ?int
    {
        $total = 0;

        foreach ($details['seasons'] ?? [] as $season) {
            $number = $season['season_number'] ?? null;
            if (null === $number || 0 === (int) $number) {
                continue;
            }

            try {
                $payload = $this->tmdbClient->getTvSeason($tvId, (int) $number);
            } catch (TmdbException $e) {
                $this->logger->warning(
                    'Could not read season {season} of TMDB series {tvId}, runtime left unknown: {message}',
                    ['season' => $number, 'tvId' => $tvId, 'message' => $e->getMessage()]
                );

                return null;
            }

            foreach ($payload['episodes'] ?? [] as $episode) {
                $total += (int) ($episode['runtime'] ?? 0);
            }
        }

        // Same reasoning as a film reporting runtime 0: an unknown duration is null, never
        // a zero that would then be summed into watch-time totals as if it were a fact.
        return $total > 0 ? $total : null;
    }
}
