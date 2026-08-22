<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Movie;
use App\Repository\MovieRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds-or-creates the Movie "stub" (title/year/slug only) referenced by a CSV row.
 * Shared by every Importer so a film only ever gets one Movie row no matter which
 * file (diary/ratings/watched/...) first mentions it, keyed on the Letterboxd slug.
 *
 * A single Importer batch flushes once at the end (see AbstractCsvImporter), so a
 * newly created Movie is not yet visible to a fresh SELECT for the rest of that
 * batch — e.g. two diary rows for the same rewatch would otherwise each miss the
 * DB lookup and insert a duplicate Movie. A local by-slug cache closes that gap.
 *
 * This service is a singleton that outlives a single import batch in the worker
 * process (messenger:consume handles many ProcessImportBatchMessage in sequence),
 * and Doctrine's EntityManager is cleared after every handled message. A cached
 * Movie from a previous batch would therefore become a detached, stale object by
 * the time a later batch (e.g. watchlist.csv running after ratings.csv) reused it
 * — reset() must be called at the start of every import() to avoid that.
 */
final class MovieUpserter
{
    /** @var array<string, Movie> */
    private array $cache = [];

    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function upsert(string $letterboxdSlug, string $title, ?int $releaseYear): Movie
    {
        if (isset($this->cache[$letterboxdSlug])) {
            return $this->cache[$letterboxdSlug];
        }

        $movie = $this->movieRepository->findOneByLetterboxdSlug($letterboxdSlug);
        if (null === $movie) {
            $movie = new Movie($letterboxdSlug, $title);
            $movie->setReleaseYear($releaseYear);
            $this->entityManager->persist($movie);
        }

        return $this->cache[$letterboxdSlug] = $movie;
    }
}
