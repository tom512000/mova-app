<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\MediaType;
use App\Exception\AmbiguousMatchException;
use App\Exception\TmdbException;
use App\Mapper\TmdbMovieMapper;
use App\Mapper\TmdbSeriesMapper;
use App\Message\EnrichMovieMessage;
use App\Repository\MovieRepository;
use App\Service\Tmdb\SeriesRuntimeResolver;
use App\Service\Tmdb\TmdbClientInterface;
use App\Service\Tmdb\TmdbResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EnrichMovieMessageHandler
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TmdbResolver $tmdbResolver,
        private readonly TmdbClientInterface $tmdbClient,
        private readonly TmdbMovieMapper $tmdbMovieMapper,
        private readonly TmdbSeriesMapper $tmdbSeriesMapper,
        private readonly SeriesRuntimeResolver $seriesRuntimeResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnrichMovieMessage $message): void
    {
        $movie = $this->movieRepository->find($message->movieId);
        if (null === $movie) {
            return;
        }

        // ENRICHED is done; EXCLUDED means a human already confirmed this Letterboxd
        // entry has no TMDB movie match (see EnrichmentStatus::EXCLUDED) — retrying it
        // on every CSV re-import is exactly what let TmdbResolver re-pick a wrong movie.
        if (\in_array($movie->getEnrichmentStatus(), [EnrichmentStatus::ENRICHED, EnrichmentStatus::EXCLUDED], true)) {
            return;
        }

        $movie->setLastEnrichmentAttemptAt(new \DateTimeImmutable());

        try {
            // A movie synced from the Letterboxd RSS feed already carries its TMDB id
            // (tmdb:movieId is in the feed itself), so searching again would be
            // redundant and could in rare cases even pick a different candidate.
            $tmdbId = $movie->getTmdbId();
            $kind = $movie->getMediaType();
            $imdbIdFromFallback = null;

            if (null === $tmdbId) {
                $resolution = $this->tmdbResolver->resolve($movie);
                // Which of TMDB's two catalogues the id belongs to. They are numbered
                // independently, so this is not a display detail: asking /movie for a
                // series id returns a real, entirely unrelated film.
                $kind = $resolution['kind'];
                $tmdbId = $resolution['tmdbId'];
                $imdbIdFromFallback = $resolution['imdbId'];
            }

            if (MediaType::SERIES === $kind) {
                $details = $this->tmdbClient->getTvDetails($tmdbId);
                $this->tmdbSeriesMapper->map(
                    $movie,
                    $details,
                    $this->seriesRuntimeResolver->totalMinutes($tmdbId, $details)
                );
            } else {
                $details = $this->tmdbClient->getMovieDetails($tmdbId);
                $this->tmdbMovieMapper->map($movie, $details);
            }

            if (null !== $imdbIdFromFallback && null === $movie->getImdbId()) {
                $movie->setImdbId($imdbIdFromFallback);
            }

            $movie->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
            $movie->setEnrichmentError(null);
        } catch (AmbiguousMatchException $e) {
            $movie->setEnrichmentStatus(EnrichmentStatus::AMBIGUOUS);
            $movie->setEnrichmentError($e->getMessage());
        } catch (TmdbException $e) {
            $movie->setEnrichmentStatus(EnrichmentStatus::FAILED);
            $movie->setEnrichmentError($e->getMessage());
        }

        $this->entityManager->flush();
    }
}
