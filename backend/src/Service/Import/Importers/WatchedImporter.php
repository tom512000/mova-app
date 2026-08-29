<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Exception\ImportRowSkippedException;
use App\Repository\WatchRepository;
use App\Service\Import\AbstractCsvImporter;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\MovieUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * watched.csv: every film marked as watched, rated or not, dated or not. The broadest
 * and least detailed of the three files — only used to make sure a Movie/Watch exists
 * at all for films that have neither a diary entry nor a rating.
 *
 * Observed columns: Date, Name, Year, Letterboxd URI. Same caveat as RatingsImporter:
 * "Date" is when the film was logged, not necessarily the real viewing date, but it's
 * used as the Watch's watchedDate since it's the only date signal this file has.
 */
final class WatchedImporter extends AbstractCsvImporter
{
    public function __construct(
        CsvReader $csvReader,
        FilmSlugResolver $slugResolver,
        MovieUpserter $movieUpserter,
        EntityManagerInterface $entityManager,
        private readonly WatchRepository $watchRepository,
    ) {
        parent::__construct($csvReader, $slugResolver, $movieUpserter, $entityManager);
    }

    public function getFileType(): ImportFileType
    {
        return ImportFileType::WATCHED;
    }

    public function supports(string $filename, array $header): bool
    {
        // Filename-exact only: watched.csv, watchlist.csv, and likes/films.csv all share
        // the identical "Date,Name,Year,Letterboxd URI" shape (confirmed against a real
        // export), so column-based detection cannot tell them apart.
        return 'watched.csv' === strtolower($filename);
    }

    protected function importRow(array $row, User $user): ?Movie
    {
        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        // A film this run just created cannot have anything attached to it yet, so the
        // lookup is skipped rather than run against a row nothing points at.
        if (!$this->movieUpserter->wasCreatedInThisRun($movie) && $this->watchRepository->hasAnyWatch($user, $movie)) {
            throw new ImportRowSkippedException();
        }

        $watch = new Watch($user, $movie, WatchSource::CSV_IMPORT);
        $watch->setWatchedDate($this->parseOptionalDate($row['Date'] ?? null));
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);

        return $movie;
    }
}
