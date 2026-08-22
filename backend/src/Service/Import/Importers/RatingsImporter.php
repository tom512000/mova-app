<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Watch;
use App\Exception\ImportRowSkippedException;
use App\Repository\WatchRepository;
use App\Service\Import\AbstractCsvImporter;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\MovieUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ratings.csv: one row per rated film, independent of whether a diary entry was logged
 * (Letterboxd lets you rate a film without a diary entry). Only used to backfill a Watch
 * for films the diary import didn't already cover — diary.csv stays the source of truth
 * whenever a dated Watch already exists for the film.
 *
 * Observed columns: Date, Name, Year, Letterboxd URI, Rating. "Date" here is when the
 * rating was logged on Letterboxd, not necessarily the real viewing date — but for an
 * account that rarely uses the diary, it's the only real date signal available for most
 * films, so it's used as the Watch's watchedDate (better an approximate timeline than an
 * empty one).
 */
final class RatingsImporter extends AbstractCsvImporter
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
        return ImportFileType::RATINGS;
    }

    public function supports(string $filename, array $header): bool
    {
        // Filename-exact only, for the same reason as DiaryImporter/WatchedImporter:
        // column-shape heuristics have proven unreliable against a real export.
        return 'ratings.csv' === strtolower($filename);
    }

    protected function importRow(array $row): ?Movie
    {
        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);
        $rating = $this->parseOptionalRating($row['Rating'] ?? null);
        $loggedDate = $this->parseOptionalDate($row['Date'] ?? null);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        // A movie with no id yet is a brand-new stub from this same import run — not flushed,
        // so it cannot have any Watch in the database yet, and querying by it would fail
        // (Doctrine can only bind a persisted entity with an identifier as a query parameter).
        if (null !== $movie->getId() && $this->watchRepository->hasAnyWatch($movie)) {
            $existing = $this->watchRepository->findOneWithoutExternalRefByMovie($movie);
            if (null !== $existing) {
                $existing->setRating($rating);
                $existing->setWatchedDate($loggedDate);

                return $movie;
            }

            // Already covered by at least one dated Watch from diary.csv — nothing to add.
            throw new ImportRowSkippedException();
        }

        $watch = new Watch($movie, WatchSource::CSV_IMPORT);
        $watch->setRating($rating);
        $watch->setWatchedDate($loggedDate);
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);

        return $movie;
    }
}
