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
 * reviews.csv: every diary entry you wrote something about.
 *
 * Observed columns: Date, Name, Year, Letterboxd URI, Rating, Rewatch, Review, Tags,
 * Watched Date — diary.csv's columns plus "Review". The URI is the *diary entry* link
 * (boxd.it/fTp0ul), the same one diary.csv carries for that entry, and not the film link
 * ratings.csv/watched.csv use. That is what makes the join exact: this importer rebuilds
 * DiaryImporter's externalRef and lands the review on the very Watch it describes.
 *
 * Only the review text is written. Rating, rewatch and tags are diary.csv's to own, and
 * re-writing them here would let a stale reviews.csv quietly undo a fresher diary.csv —
 * they are set only when this importer has to create the Watch itself.
 */
final class ReviewsImporter extends AbstractCsvImporter
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
        return ImportFileType::REVIEWS;
    }

    public function supports(string $filename, array $header): bool
    {
        // Filename-exact, for the same reason DiaryImporter is: the two files differ by a
        // single column, so a shape match would have them fighting over each other.
        return 'reviews.csv' === strtolower($filename);
    }

    protected function importRow(array $row, User $user): ?Movie
    {
        $review = trim((string) ($row['Review'] ?? ''));
        if ('' === $review) {
            // Nothing to attach. Letterboxd does not export empty reviews, so this is a
            // hand-edited file rather than a malformed one — skipped, not failed.
            throw new ImportRowSkippedException();
        }

        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);

        $watchedDate = $this->parseOptionalDate($row['Watched Date'] ?? null)
            ?? $this->parseOptionalDate($row['Date'] ?? null);

        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        $this->watchFor($user, $movie, $letterboxdUri, $watchedDate, $row)->setReviewText($review);

        return $movie;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function watchFor(
        User $user,
        Movie $movie,
        string $letterboxdUri,
        ?\DateTimeImmutable $watchedDate,
        array $row,
    ): Watch {
        $externalRef = sprintf('diary:%s:%s', $letterboxdUri, $watchedDate?->format('Y-m-d') ?? 'unknown');

        // The diary entry itself, when diary.csv has already been through (it runs first,
        // by importPriority). This is the common case and the only exact one.
        $watch = $this->watchRepository->findOneByExternalRef($user, $externalRef);
        if (null !== $watch) {
            return $watch;
        }

        // Failing that, the viewing on the same day — which is where the review belongs if
        // the row it came from was only ever seen by ratings.csv or watched.csv.
        //
        // Only worth asking once the film has an id: MovieUpserter hands back unflushed
        // entities for films it has just created, and Doctrine cannot bind those to a
        // query — nor would it need to, since a film created a moment ago has no viewings.
        if (null !== $watchedDate && null !== $movie->getId()) {
            $watch = $this->watchRepository->findOneByMovieAndWatchedDate($user, $movie, $watchedDate);
            if (null !== $watch) {
                return $watch;
            }
        }

        // Nothing to hang it on: reviews.csv was uploaded without its diary.csv. Creating
        // the row under the ref diary.csv would have used means a later upload finds and
        // completes this one instead of doubling it.
        $watch = new Watch($user, $movie, WatchSource::CSV_IMPORT);
        $watch->setExternalRef($externalRef);
        $watch->setWatchedDate($watchedDate);
        $watch->setRating($this->parseOptionalRating($row['Rating'] ?? null));
        $watch->setIsRewatch($this->parseBooleanFlag($row['Rewatch'] ?? null));
        $this->entityManager->persist($watch);
        $movie->addWatch($watch);

        return $watch;
    }
}
