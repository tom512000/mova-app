<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

use App\Entity\Movie;
use App\Exception\AmbiguousMatchException;
use App\Service\Letterboxd\LetterboxdFilmPageResolverInterface;

/**
 * Resolves the TMDB id for a Movie stub imported from a Letterboxd CSV export, which
 * never carries one. Strategy:
 *
 *   1. Read the TMDB/IMDb links Letterboxd itself publishes on the film's public page
 *      (letterboxd.com/film/<slug>/). The slug comes straight from the export, so this
 *      is an exact mapping rather than a guess — one request per unique film, cached
 *      permanently by LetterboxdFilmPageResolver.
 *   2. Only if that page yields nothing, fall back to TMDB /search/movie by title+year,
 *      scored by title similarity + year match.
 *   3. If neither works, throw AmbiguousMatchException — never guess silently.
 *
 * The search used to come first, which produced *confidently wrong* matches: TmdbClient
 * queries TMDB with language=fr-FR, so a candidate's `title` and `original_title` are its
 * French and original titles — never the English/international title Letterboxd exports.
 * "Back to School" (slug back-to-school-2019) is the French film "La Grande Classe", which
 * therefore scored ~0 on title similarity while an unrelated 1-vote short literally named
 * "Back To School" scored a perfect 1.0 and won. Every foreign film whose Letterboxd title
 * differs from its TMDB title hits that same hole, so title scoring cannot be the primary
 * signal — see App\Command\AuditTmdbMatchesCommand, which repairs libraries imported before
 * this order was flipped.
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

        $page = $this->letterboxdFilmPageResolver->resolve($movie->getLetterboxdSlug());
        if (null !== $page['tmdbId']) {
            return ['tmdbId' => $page['tmdbId'], 'imdbId' => $page['imdbId']];
        }

        // Letterboxd links this entry to a TMDB *series*: no movie can be correct here, and
        // searching /search/movie anyway is exactly what used to attach a random film to it.
        if (null !== $page['tmdbTvId']) {
            throw new AmbiguousMatchException(sprintf(
                '"%s" est une série TMDB (tv/%d) côté Letterboxd, aucun film correspondant.',
                $title,
                $page['tmdbTvId']
            ));
        }

        $best = $this->findBestSearchCandidate($title, $year);
        if (null !== $best) {
            return ['tmdbId' => $best, 'imdbId' => null];
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
