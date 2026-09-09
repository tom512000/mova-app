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
 * watched.csv: every film marked as watched, rated or not, dated or not.
 *
 * Observed columns: Date, Name, Year, Letterboxd URI. That Date is when the film was marked
 * as seen, which is the closest thing to a real viewing date this export offers outside the
 * diary — and it is a different fact from the one in ratings.csv, which dates the rating.
 *
 * This file used to do nothing at all. It ran last and skipped any film that already had a
 * viewing, so on a real export all 738 of its rows were discarded and every date in the
 * library came from ratings.csv. That is why a sitting spent rating eight films put all
 * eight on the same square of the calendar: the two files disagree for 122 films out of 738,
 * by a median of two weeks and by as much as sixteen months.
 *
 * It now has two jobs. It still creates a viewing for a film nothing else knows about, and
 * it corrects the date of one that ratings.csv guessed — only ever backwards, and never on a
 * row that diary.csv owns or that stands for a revised rating.
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
        $markedOn = $this->parseOptionalDate($row['Date'] ?? null);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        // A film this run just created cannot have anything attached to it yet, so the
        // lookup is skipped rather than run against a row nothing points at.
        $isNew = $this->movieUpserter->wasCreatedInThisRun($movie);
        $correctable = $isNew ? null : $this->watchRepository->findEarliestCorrectableByMovie($user, $movie);

        if (null !== $correctable) {
            return $this->correctDate($correctable, $movie, $markedOn);
        }

        // Nothing this file may move. That is either a film nobody has recorded a viewing for
        // — where this file is the only source and a row has to be made — or a film whose
        // every viewing belongs to diary.csv, where making a second one would invent an
        // evening beside a real one.
        if (!$isNew && $this->watchRepository->hasAnyWatch($user, $movie)) {
            throw new ImportRowSkippedException();
        }

        $watch = new Watch($user, $movie, WatchSource::CSV_IMPORT);
        $watch->setWatchedDate($markedOn);
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);

        return $movie;
    }

    /**
     * Moves a viewing back to the day the film was actually marked as seen.
     *
     * This file used to be skipped outright whenever a Watch already existed, which on a real
     * export meant every one of its 738 rows did nothing. That was a waste of the best date
     * signal available: ratings.csv dates the rating, and rating eight films in one sitting
     * stamps all eight with that evening. Against a real export the two files disagree for
     * 122 films, by a median of two weeks and by as much as sixteen months.
     *
     * Only ever backwards. A date later than the one on record is this file being vaguer than
     * whatever wrote it — diary.csv gives a real viewing date, and a rewatch logged there is
     * newer than the day the film was first marked seen.
     */
    private function correctDate(Watch $existing, Movie $movie, ?\DateTimeImmutable $markedOn): Movie
    {
        if (null === $markedOn) {
            throw new ImportRowSkippedException();
        }

        $current = $existing->getWatchedDate();
        if (null !== $current && $markedOn >= $current) {
            throw new ImportRowSkippedException();
        }

        $existing->setWatchedDate($markedOn);

        return $movie;
    }
}
