<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\EnrichmentStatus;
use App\Exception\AmbiguousMatchException;
use App\Exception\TmdbException;
use App\Mapper\TmdbMovieMapper;
use App\Message\EnrichMovieMessage;
use App\Repository\MovieRepository;
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
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnrichMovieMessage $message): void
    {
        $movie = $this->movieRepository->find($message->movieId);
        if (null === $movie || EnrichmentStatus::ENRICHED === $movie->getEnrichmentStatus()) {
            return;
        }

        $movie->setLastEnrichmentAttemptAt(new \DateTimeImmutable());

        try {
            $resolution = $this->tmdbResolver->resolve($movie);
            $details = $this->tmdbClient->getMovieDetails($resolution['tmdbId']);
            $this->tmdbMovieMapper->map($movie, $details);

            if (null !== $resolution['imdbId'] && null === $movie->getImdbId()) {
                $movie->setImdbId($resolution['imdbId']);
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
