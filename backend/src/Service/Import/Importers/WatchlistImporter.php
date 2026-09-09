<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\WatchlistEntry;
use App\Repository\WatchlistEntryRepository;
use App\Service\Import\AbstractCsvImporter;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\MovieUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * watchlist.csv: films the user wants to watch but hasn't yet. Same column shape as
 * watched.csv/likes/films.csv, hence filename-exact matching (see WatchedImporter).
 *
 * Observed columns: Date, Name, Year, Letterboxd URI. "Date" is when the film was added
 * to the watchlist.
 */
final class WatchlistImporter extends AbstractCsvImporter
{
    public function __construct(
        CsvReader $csvReader,
        FilmSlugResolver $slugResolver,
        MovieUpserter $movieUpserter,
        EntityManagerInterface $entityManager,
        private readonly WatchlistEntryRepository $watchlistEntryRepository,
    ) {
        parent::__construct($csvReader, $slugResolver, $movieUpserter, $entityManager);
    }

    public function getFileType(): ImportFileType
    {
        return ImportFileType::WATCHLIST;
    }

    public function supports(string $filename, array $header): bool
    {
        return 'watchlist.csv' === strtolower($filename);
    }

    protected function importRow(array $row, User $user): ?Movie
    {
        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);
        $addedDate = $this->parseOptionalDate($row['Date'] ?? null);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        // A movie with no id yet is a brand-new stub from this same import run — not flushed,
        // so it cannot have any WatchlistEntry in the database yet (see other importers for
        // the same pattern with Watch).
        $existing = $this->movieUpserter->wasCreatedInThisRun($movie)
            ? null
            : $this->watchlistEntryRepository->findOneByMovie($user, $movie);
        if (null !== $existing) {
            $existing->setAddedDate($addedDate);

            return $movie;
        }

        $entry = new WatchlistEntry($user, $movie);
        $entry->setAddedDate($addedDate);
        $this->entityManager->persist($entry);

        return $movie;
    }

    /**
     * Drops the entries this export no longer names.
     *
     * watchlist.csv is the only Letterboxd file that is a *snapshot* rather than a log: it
     * lists the watchlist as it stands, so a film missing from it has left the watchlist and
     * that absence is information. Every other importer is right to ignore what its file does
     * not say — a film missing from diary.csv was simply not watched again.
     *
     * Reading it as additive was the bug: watching a film takes it off the Letterboxd
     * watchlist, so the export stops naming it, and the app kept the row for ever. Twenty-two
     * of two hundred and seven entries were films already seen, and some had been removed by
     * hand without ever being watched — which nothing but this could have noticed.
     *
     * The guard is against a file that parsed but arrived incomplete. A snapshot naming
     * nothing is not the claim "your watchlist is empty", it is a broken file, and acting on
     * it would delete the lot. An export that genuinely holds nothing leaves the stored rows
     * alone; they go on the first import that names at least one.
     */
    protected function afterRows(User $user, array $touchedMovies, ImportBatch $batch): void
    {
        if ([] === $touchedMovies) {
            return;
        }

        $keptIds = [];
        foreach ($touchedMovies as $movie) {
            $keptIds[(string) $movie->getId()] = true;
        }

        foreach ($this->watchlistEntryRepository->findBy(['user' => $user]) as $entry) {
            if (!isset($keptIds[(string) $entry->getMovie()->getId()])) {
                $this->entityManager->remove($entry);
            }
        }
    }
}
