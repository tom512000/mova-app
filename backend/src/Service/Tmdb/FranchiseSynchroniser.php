<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

use App\Entity\Franchise;
use App\Entity\FranchiseFilm;
use App\Entity\Movie;
use App\Exception\TmdbException;
use App\Repository\FranchiseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Attaches a film to its TMDB franchise, and fills that franchise in the first time it is
 * seen.
 *
 * Two different costs, kept apart on purpose. Naming the franchise is free: every
 * /movie/{id} response already carries `belongs_to_collection`, and the app has been
 * throwing it away since the first import. Listing what is *in* the franchise costs one call
 * to /collection/{id} — one per franchise, not one per film, so a nine-film saga costs one
 * request however many of its films the library holds.
 *
 * A franchise that already has its films is left alone. Re-fetching on every enrichment
 * would multiply the cost by the size of the library for a list that changes when a sequel
 * is announced, which is not often; pass $refresh when that is what you actually want.
 *
 * Series never reach here. TMDB has no collection concept on /tv, so there is nothing to
 * attach them to.
 */
final class FranchiseSynchroniser
{
    /**
     * Franchises created during this run but not yet flushed.
     *
     * Without it, two films of the same saga enriched in one batch each create their own
     * Franchise row and the unique index rejects the second — the same reason the mappers
     * keep a person cache.
     *
     * @var array<int, Franchise>
     */
    private array $cache = [];

    public function __construct(
        private readonly TmdbClientInterface $tmdbClient,
        private readonly FranchiseRepository $franchiseRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Reads `belongs_to_collection` off a movie detail payload and links the film to it.
     *
     * @param array<string, mixed> $details a /movie/{id} response
     *
     * @return Franchise|null the franchise the film was attached to, or null if it has none
     */
    public function attach(Movie $movie, array $details, bool $refresh = false): ?Franchise
    {
        $payload = $details['belongs_to_collection'] ?? null;
        if (!\is_array($payload) || !isset($payload['id'])) {
            // Most films belong to nothing, and a film can also leave a collection on TMDB.
            // Either way the link is cleared rather than left pointing at yesterday's answer.
            $movie->setFranchise(null);

            return null;
        }

        $franchise = $this->findOrCreate((int) $payload['id'], (string) ($payload['name'] ?? ''));
        $franchise->setPosterPath($this->pathOrNull($payload['poster_path'] ?? null));

        if ($refresh || $franchise->getFilms()->isEmpty()) {
            $this->fillFilms($franchise);
        }

        $movie->setFranchise($franchise);

        return $franchise;
    }

    /**
     * Drops the not-yet-flushed cache. Call it after clearing the entity manager, or the
     * next film would be attached to a Franchise Doctrine has stopped managing.
     */
    public function resetCache(): void
    {
        $this->cache = [];
    }

    private function findOrCreate(int $tmdbId, string $name): Franchise
    {
        if (isset($this->cache[$tmdbId])) {
            return $this->cache[$tmdbId];
        }

        $franchise = $this->franchiseRepository->findOneByTmdbId($tmdbId);
        if (null === $franchise) {
            $franchise = (new Franchise())->setTmdbId($tmdbId);
            $this->entityManager->persist($franchise);
        }

        // Refreshed even on an existing row: TMDB renames collections, and a stale name is
        // the one thing a reader would notice.
        $franchise->setName('' !== $name ? $name : 'Saga '.$tmdbId);

        return $this->cache[$tmdbId] = $franchise;
    }

    private function fillFilms(Franchise $franchise): void
    {
        try {
            $collection = $this->tmdbClient->getCollection($franchise->getTmdbId());
        } catch (TmdbException) {
            // A franchise whose parts could not be fetched is still worth attaching: the
            // film keeps its saga name, and the count simply stays unknown until the next
            // run. Failing the whole enrichment over it would be out of proportion.
            return;
        }

        $parts = $collection['parts'] ?? [];
        if (!\is_array($parts) || [] === $parts) {
            return;
        }

        // Rebuilt wholesale rather than diffed. orphanRemoval turns the clear into deletes,
        // and the alternative — matching rows by tmdbId to decide what to keep — is more
        // code guarding a table nobody writes to by hand.
        $franchise->clearFilms();

        $seen = [];
        foreach ($parts as $part) {
            if (!\is_array($part) || !isset($part['id'])) {
                continue;
            }

            $tmdbId = (int) $part['id'];
            // TMDB has been known to list the same film twice in a collection, and the
            // unique index would reject the second one mid-flush.
            if (isset($seen[$tmdbId])) {
                continue;
            }
            $seen[$tmdbId] = true;

            $title = trim((string) ($part['title'] ?? $part['original_title'] ?? ''));
            if ('' === $title) {
                continue;
            }

            $film = new FranchiseFilm($franchise, $tmdbId, $title);
            $film->setReleaseDate($this->dateOrNull($part['release_date'] ?? null));
            $film->setPosterPath($this->pathOrNull($part['poster_path'] ?? null));

            $franchise->addFilm($film);
            $this->entityManager->persist($film);
        }
    }

    /**
     * TMDB writes an empty string for an unknown release date rather than omitting the
     * field, and an announced sequel with no date is a legitimate row here.
     */
    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function pathOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
