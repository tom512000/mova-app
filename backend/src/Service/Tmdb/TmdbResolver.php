<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

use App\Entity\Movie;
use App\Exception\AmbiguousMatchException;
use App\Service\Letterboxd\LetterboxdFilmPageResolverInterface;

/**
 * Resolves the TMDB id for a Movie stub imported from a Letterboxd CSV export, which
 * never carries one. Strategy (validated with the user given Letterboxd exports have
 * no external ids at all):
 *
 *   1. TMDB /search/movie by title+year, scored by title similarity + year match.
 *   2. If no candidate is confident enough, fall back to reading the TMDB/IMDb links
 *      embedded in the film's public Letterboxd page (letterboxd.com/film/<slug>/).
 *   3. If neither works, throw AmbiguousMatchException — never guess silently.
 */
final class TmdbResolver
{
    private const CONFIDENT_SCORE = 0.85;
    private const RUNNER_UP_MARGIN = 0.1;

    public function __construct(
        private readonly TmdbClientInterface $tmdbClient,
        private readonly TitleNormalizer $titleNormalizer,
        private readonly LetterboxdFilmPageResolverInterface $letterboxdFilmPageResolver,
    ) {
    }

    /**
     * @return array{tmdbId: int, imdbId: string|null}
     *
     * @throws AmbiguousMatchException
     */
    public function resolve(Movie $movie): array
    {
        $title = $movie->getTitle();
        $year = $movie->getReleaseYear();

        $best = $this->findBestSearchCandidate($title, $year);
        if (null !== $best) {
            return ['tmdbId' => $best, 'imdbId' => null];
        }

        $pageResult = $this->letterboxdFilmPageResolver->resolve($movie->getLetterboxdSlug());
        if (null !== $pageResult['tmdbId']) {
            return ['tmdbId' => $pageResult['tmdbId'], 'imdbId' => $pageResult['imdbId']];
        }

        throw new AmbiguousMatchException(sprintf(
            'Aucune correspondance TMDB fiable pour "%s" (%s).',
            $title,
            $year ?? 'année inconnue'
        ));
    }

    private function findBestSearchCandidate(string $title, ?int $year): ?int
    {
        $results = $this->tmdbClient->searchMovie($title, $year);
        if ([] === $results) {
            return null;
        }

        $scored = [];
        foreach ($results as $candidate) {
            $scored[] = [
                'id' => $candidate['id'],
                'score' => $this->score($title, $year, $candidate),
            ];
        }

        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $top = $scored[0];
        $runnerUp = $scored[1] ?? null;

        $isConfident = $top['score'] >= self::CONFIDENT_SCORE
            && (null === $runnerUp || $top['score'] - $runnerUp['score'] >= self::RUNNER_UP_MARGIN);

        return $isConfident ? $top['id'] : null;
    }

    /**
     * @param array{title?: string, original_title?: string, release_date?: string} $candidate
     */
    private function score(string $title, ?int $year, array $candidate): float
    {
        $titleSimilarity = max(
            $this->titleNormalizer->similarity($title, $candidate['title'] ?? ''),
            $this->titleNormalizer->similarity($title, $candidate['original_title'] ?? ''),
        );

        $candidateYear = null;
        if (!empty($candidate['release_date'])) {
            $candidateYear = (int) substr($candidate['release_date'], 0, 4);
        }

        $yearScore = match (true) {
            null === $year || null === $candidateYear => 0.5, // neutral when unknown
            $year === $candidateYear => 1.0,
            1 === abs($year - $candidateYear) => 0.5,
            default => 0.0,
        };

        return $titleSimilarity * 0.7 + $yearScore * 0.3;
    }
}
