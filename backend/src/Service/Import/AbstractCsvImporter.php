<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ImportBatch;
use App\Entity\ImportRowError;
use App\Entity\Movie;
use App\Entity\User;
use App\Exception\ImportRowException;
use App\Exception\ImportRowSkippedException;
use Doctrine\ORM\EntityManagerInterface;

abstract class AbstractCsvImporter implements ImporterInterface
{
    public function __construct(
        protected readonly CsvReader $csvReader,
        protected readonly FilmSlugResolver $slugResolver,
        protected readonly MovieUpserter $movieUpserter,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int[]
     */
    public function import(string $filepath, ImportBatch $batch): array
    {
        // MovieUpserter is a singleton that outlives this one batch in the worker process,
        // and Doctrine clears its EntityManager after every handled message — so a Movie
        // cached from an earlier batch would be a stale, detached object by now.
        $this->movieUpserter->reset();

        // Movie objects are collected rather than ids: newly persisted Movies have no id
        // until the single flush below runs (Postgres IDENTITY columns are only assigned
        // at flush time), but the object reference stays valid and ->getId() resolves after.
        $touchedMovies = [];

        // The owner rides along on the batch: a worker process has no ambient current user,
        // so every Watch/WatchlistEntry the row creates has to be told whose it is.
        $user = $batch->getUser();

        foreach ($this->csvReader->readAssoc($filepath) as $rowNumber => $row) {
            try {
                $movie = $this->importRow($row, $user);
                if (null !== $movie) {
                    $touchedMovies[spl_object_id($movie)] = $movie;
                }
                $batch->incrementRowsImported();
            } catch (ImportRowSkippedException) {
                $batch->incrementRowsSkipped();
            } catch (ImportRowException $e) {
                $batch->incrementRowsFailed();
                $rowError = new ImportRowError($batch, $rowNumber, $row, $e->getMessage());
                $this->entityManager->persist($rowError);
                $batch->addRowError($rowError);
            }
        }

        $this->entityManager->flush();

        return array_values(array_unique(array_map(static fn (Movie $m) => (string) $m->getId(), $touchedMovies)));
    }

    /**
     * @param array<string, string|null> $row
     *
     * @throws ImportRowException for a row that cannot be imported (isolates the failure to this row)
     */
    abstract protected function importRow(array $row, User $user): ?Movie;

    /**
     * @param array<string, string|null> $row
     */
    protected function requireColumn(array $row, string $column): string
    {
        $value = trim((string) ($row[$column] ?? ''));
        if ('' === $value) {
            throw new ImportRowException(sprintf('Colonne "%s" manquante ou vide.', $column));
        }

        return $value;
    }

    protected function requireSlug(string $letterboxdUri): string
    {
        $slug = $this->slugResolver->resolve($letterboxdUri);
        if (null === $slug) {
            throw new ImportRowException(sprintf('Impossible de résoudre le film depuis l\'URI "%s".', $letterboxdUri));
        }

        return $slug;
    }

    protected function parseOptionalYear(?string $value): ?int
    {
        $value = trim((string) $value);
        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function parseOptionalDate(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date) {
            throw new ImportRowException(sprintf('Date invalide : "%s" (attendu AAAA-MM-JJ).', $value));
        }

        return $date;
    }

    protected function parseOptionalRating(?string $value): ?float
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new ImportRowException(sprintf('Note invalide : "%s".', $value));
        }

        $rating = (float) $value;
        if ($rating < 0.5 || $rating > 5.0) {
            throw new ImportRowException(sprintf('Note hors plage (0.5 à 5.0) : "%s".', $value));
        }

        return $rating;
    }

    protected function parseBooleanFlag(?string $value): bool
    {
        return \in_array(strtolower(trim((string) $value)), ['yes', 'true', '1', 'x'], true);
    }

    /**
     * @return string[]
     */
    protected function parseTags(?string $value): array
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $tag) => '' !== $tag));
    }
}
