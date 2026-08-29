<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;
use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\WatchSource;
use App\Entity\LetterboxdSyncState;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Exception\LetterboxdRssException;
use App\Message\EnrichMovieMessage;
use App\Repository\LetterboxdSyncStateRepository;
use App\Repository\MovieRepository;
use App\Repository\WatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LetterboxdRssClientInterface $rssClient,
        private readonly MovieRepository $movieRepository,
        private readonly WatchRepository $watchRepository,
        private readonly LetterboxdSyncStateRepository $syncStateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(User $user): void
    {
        $username = $user->getLetterboxdUsername();
        if (null === $username) {
            throw new LetterboxdRssException('Aucun compte Letterboxd configuré sur ce profil.');
        }

        $state = $this->syncStateRepository->findOneByUser($user);
        if (null === $state) {
            $state = new LetterboxdSyncState($user, $username);
            $this->entityManager->persist($state);
        } else {
            // The account can be repointed at a different Letterboxd username; the state row
            // tracks the user, so keep its label in step rather than stranding the old one.
            $state->setUsername($username);
        }

        try {
            $imported = $this->importNewEntries($user, $username);
            $state->markSuccess($imported);
        } catch (\Throwable $e) {
            $this->logger->error('Letterboxd RSS sync failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $state->markFailed($e->getMessage());
        }

        $this->entityManager->flush();
    }

    private function importNewEntries(User $user, string $username): int
    {
        $entries = $this->rssClient->fetchDiaryEntries($username);

        $touchedMovies = [];
        $imported = 0;

        foreach ($entries as $entry) {
            if (null !== $this->watchRepository->findOneByExternalRef($user, $entry->guid)) {
                continue; // already synced on a previous run
            }

            $movie = $this->findOrCreateMovie($entry);

            $watch = new Watch($user, $movie, WatchSource::RSS_SYNC);
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
            // Was `!== ENRICHED`, which still queued films deliberately marked EXCLUDED.
            if ($movie->getEnrichmentStatus()->needsEnrichment()) {
                $this->messageBus->dispatch(new EnrichMovieMessage((string) $movie->getId()));
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
