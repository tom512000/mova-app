<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;
use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\WatchSource;
use App\Entity\LetterboxdSyncState;
use App\Entity\Movie;
use App\Entity\Watch;
use App\Exception\LetterboxdRssException;
use App\Message\EnrichMovieMessage;
use App\Repository\LetterboxdSyncStateRepository;
use App\Repository\MovieRepository;
use App\Repository\WatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pulls the user's Letterboxd RSS feed and turns any new diary entry into a Watch,
 * the same way the CSV importers do — except the feed already carries a TMDB id per
 * entry, so enrichment skips TmdbResolver's search-based guessing entirely (see
 * EnrichMovieMessageHandler). Idempotent on Watch.externalRef (the RSS item guid),
 * exactly like DiaryImporter's diary.csv rows.
 */
final class LetterboxdRssSyncService
{
    public function __construct(
        #[Autowire('%app.letterboxd.username%')]
        private readonly string $username,
        private readonly LetterboxdRssClientInterface $rssClient,
        private readonly MovieRepository $movieRepository,
        private readonly WatchRepository $watchRepository,
        private readonly LetterboxdSyncStateRepository $syncStateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(): void
    {
        if ('' === $this->username) {
            throw new LetterboxdRssException('LETTERBOXD_USERNAME non configuré.');
        }

        $state = $this->syncStateRepository->findOneByUsername($this->username);
        if (null === $state) {
            $state = new LetterboxdSyncState($this->username);
            $this->entityManager->persist($state);
        }

        try {
            $imported = $this->importNewEntries();
            $state->markSuccess($imported);
        } catch (\Throwable $e) {
            $this->logger->error('Letterboxd RSS sync failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $state->markFailed($e->getMessage());
        }

        $this->entityManager->flush();
    }

    private function importNewEntries(): int
    {
        $entries = $this->rssClient->fetchDiaryEntries($this->username);

        $touchedMovies = [];
        $imported = 0;

        foreach ($entries as $entry) {
            if (null !== $this->watchRepository->findOneByExternalRef($entry->guid)) {
                continue; // already synced on a previous run
            }

            $movie = $this->findOrCreateMovie($entry);

            $watch = new Watch($movie, WatchSource::RSS_SYNC);
            $watch->setExternalRef($entry->guid);
            $watch->setWatchedDate($entry->watchedDate);
            $watch->setRating($entry->rating);
            $watch->setIsRewatch($entry->isRewatch);
            $watch->setReviewText($entry->reviewText);
            $this->entityManager->persist($watch);
            $movie->addWatch($watch);

            $touchedMovies[spl_object_id($movie)] = $movie;
            ++$imported;
        }

        $this->entityManager->flush();

        foreach ($touchedMovies as $movie) {
            if (EnrichmentStatus::ENRICHED !== $movie->getEnrichmentStatus()) {
                $this->messageBus->dispatch(new EnrichMovieMessage($movie->getId()));
            }
        }

        return $imported;
    }

    private function findOrCreateMovie(RssDiaryEntry $entry): Movie
    {
        // Prefer the tmdb id the feed itself gives us — it's the most reliable key
        // available. Fall back to the slug in case a CSV import already created this
        // Movie as an unenriched stub (no tmdb_id yet), to avoid a duplicate row.
        $movie = $this->movieRepository->findOneByTmdbId($entry->tmdbMovieId)
            ?? $this->movieRepository->findOneByLetterboxdSlug($entry->filmSlug);

        if (null === $movie) {
            $movie = new Movie($entry->filmSlug, $entry->filmTitle);
            $movie->setReleaseYear($entry->filmYear);
            $movie->setTmdbId($entry->tmdbMovieId);
            $this->entityManager->persist($movie);

            return $movie;
        }

        if (null === $movie->getTmdbId()) {
            $movie->setTmdbId($entry->tmdbMovieId);
        }

        return $movie;
    }
}
