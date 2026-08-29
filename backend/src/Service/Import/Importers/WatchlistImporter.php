<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
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
}
