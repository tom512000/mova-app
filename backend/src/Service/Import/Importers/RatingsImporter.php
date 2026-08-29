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
 *
 * That Date moving is the only trace a second opinion leaves. Re-rating a film from its page
 * does not write a diary entry: Letterboxd rewrites this one row, the rating changes and the
 * Date advances, and the previous values vanish from the export. This importer used to
 * overwrite both, so the earlier opinion was destroyed on every import — confirmed against
 * two consecutive exports, where L'Arnacœur went from 3.5 on the 21st to 4 on the 25th and
 * the 3.5 existed nowhere afterwards.
 *
 * So a row whose date has moved forward is recorded as a viewing of its own rather than as a
 * correction of the previous one. See WatchSource::CSV_RERATING for why that is a smaller
 * claim than it looks.
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

    protected function importRow(array $row, User $user): ?Movie
    {
        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);
        $rating = $this->parseOptionalRating($row['Rating'] ?? null);
        $loggedDate = $this->parseOptionalDate($row['Date'] ?? null);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        // A film this run just created cannot have anything attached to it yet, so the
        // lookup is skipped rather than run against a row nothing points at — and it could
        // not be run anyway: MovieUpserter hands back unflushed entities, which Doctrine
        // cannot bind as a query parameter.
        $latest = $this->movieUpserter->wasCreatedInThisRun($movie)
            ? null
            : $this->watchRepository->findLatestByMovie($user, $movie);

        if (null === $latest) {
            return $this->record($user, $movie, $loggedDate, $rating, WatchSource::CSV_IMPORT);
        }

        // No date to compare against, on either side. Nothing can be told apart, so the
        // safest reading is that this is the same viewing being re-imported.
        if (null === $loggedDate || null === $latest->getWatchedDate()) {
            return $this->updateInPlace($latest, $movie, $rating, $loggedDate);
        }

        $comparison = $loggedDate <=> $latest->getWatchedDate();

        if ($comparison > 0) {
            // The date has moved forward since the last import: a second opinion, recorded
            // as its own viewing instead of overwriting the first.
            return $this->record($user, $movie, $loggedDate, $rating, WatchSource::CSV_RERATING);
        }

        if ($comparison < 0) {
            // An older export loaded after a newer one. Rewinding the library to it would
            // undo a viewing that has already been recorded, so the row is left alone.
            throw new ImportRowSkippedException();
        }

        return $this->updateInPlace($latest, $movie, $rating, $loggedDate);
    }

    /**
     * The same viewing, seen again in a later export. Only the rating is written, and only
     * onto a row diary.csv does not own.
     */
    private function updateInPlace(Watch $latest, Movie $movie, ?float $rating, ?\DateTimeImmutable $loggedDate): Movie
    {
        if (null !== $latest->getExternalRef()) {
            // A diary entry. diary.csv is the source of truth for everything on it.
            throw new ImportRowSkippedException();
        }

        $latest->setRating($rating);
        $latest->setWatchedDate($loggedDate ?? $latest->getWatchedDate());

        return $movie;
    }

    private function record(User $user, Movie $movie, ?\DateTimeImmutable $watchedDate, ?float $rating, WatchSource $source): Movie
    {
        $watch = new Watch($user, $movie, $source);
        $watch->setRating($rating);
        $watch->setWatchedDate($watchedDate);
        // Not a claim that Letterboxd said so — it never does for these — but the flag the
        // rest of the application reads to mean "not the first time".
        $watch->setIsRewatch($source->isDeduced());
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);

        return $movie;
    }
}
