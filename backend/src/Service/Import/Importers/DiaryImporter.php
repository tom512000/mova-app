<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\ImportBatch;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\WatchRepository;
use App\Service\Import\AbstractCsvImporter;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\MovieUpserter;
use App\Service\Import\TagUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * diary.csv: one row per logged watch (has a watched date), including rewatches and tags.
 * This is the richest file and the primary source of Watch rows.
 *
 * Observed columns: Date, Name, Year, Letterboxd URI, Rating, Rewatch, Tags, Watched Date.
 * "Date" is when the entry was logged on the site; "Watched Date" is the actual viewing
 * date and is what we store — falling back to "Date" if a given export omits the column,
 * since the export format isn't officially documented and has been seen to vary.
 */
final class DiaryImporter extends AbstractCsvImporter
{
    public function __construct(
        CsvReader $csvReader,
        FilmSlugResolver $slugResolver,
        MovieUpserter $movieUpserter,
        EntityManagerInterface $entityManager,
        private readonly WatchRepository $watchRepository,
        private readonly TagUpserter $tagUpserter,
    ) {
        parent::__construct($csvReader, $slugResolver, $movieUpserter, $entityManager);
    }

    public function import(string $filepath, ImportBatch $batch): array
    {
        // Same reason AbstractCsvImporter resets the MovieUpserter: the cache must not
        // outlive the batch it was filled for.
        $this->tagUpserter->reset();

        return parent::import($filepath, $batch);
    }

    public function getFileType(): ImportFileType
    {
        return ImportFileType::DIARY;
    }

    public function supports(string $filename, array $header): bool
    {
        // Filename-exact only: reviews.csv has every diary.csv column plus "Review",
        // so a "Rewatch" + "Watched Date" shape match would misclassify it too
        // (confirmed against a real export).
        return 'diary.csv' === strtolower($filename);
    }

    protected function importRow(array $row, User $user): ?Movie
    {
        $name = $this->requireColumn($row, 'Name');
        $letterboxdUri = $this->requireColumn($row, 'Letterboxd URI');
        $slug = $this->requireSlug($letterboxdUri);

        $watchedDate = $this->parseOptionalDate($row['Watched Date'] ?? null)
            ?? $this->parseOptionalDate($row['Date'] ?? null);

        $externalRef = sprintf('diary:%s:%s', $letterboxdUri, $watchedDate?->format('Y-m-d') ?? 'unknown');

        $watch = $this->watchRepository->findOneByExternalRef($user, $externalRef);
        $movie = $this->movieUpserter->upsert($slug, $name, $this->parseOptionalYear($row['Year'] ?? null));

        if (null === $watch) {
            $watch = new Watch($user, $movie, WatchSource::CSV_IMPORT);
            $watch->setExternalRef($externalRef);
            $this->entityManager->persist($watch);
            $movie->addWatch($watch);
        }

        $watch->setWatchedDate($watchedDate);
        $watch->setRating($this->parseOptionalRating($row['Rating'] ?? null));
        $watch->setIsRewatch($this->parseBooleanFlag($row['Rewatch'] ?? null));

        $watch->clearTags();
        foreach ($this->parseTags($row['Tags'] ?? null) as $tagName) {
            $watch->addTag($this->tagUpserter->upsert($tagName));
        }

        return $movie;
    }
}
