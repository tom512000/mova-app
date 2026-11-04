<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;
use App\Entity\ImportRowError;
use App\Entity\Movie;
use App\Entity\MovieList;
use App\Entity\MovieListEntry;
use App\Exception\ImportException;
use App\Exception\ImportRowException;
use App\Repository\MovieListRepository;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\ImporterInterface;
use App\Service\Import\ListCsvReader;
use App\Service\Import\ListExport;
use App\Service\Import\MovieUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * lists/*.csv: one Letterboxd list per file.
 *
 * The only importer recognised by content rather than by name. Every other one matches its
 * filename exactly because their column shapes overlap; a list file opens with a signature
 * line no other export carries, and its name is the list's slug — which its owner chose and
 * which could be anything at all, "diary" included. Matching on the signature also means a
 * single list exported from its own page on Letterboxd imports the same way.
 *
 * A list is a snapshot, like watchlist.csv and unlike the logs: the file says what the list
 * holds today, so a film it no longer names has been taken off. The list is refreshed whole
 * on every import — name, description, order and membership.
 */
final class ListImporter implements ImporterInterface
{
    public function __construct(
        private readonly ListCsvReader $listReader,
        private readonly FilmSlugResolver $slugResolver,
        private readonly MovieUpserter $movieUpserter,
        private readonly MovieListRepository $lists,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFileType(): ImportFileType
    {
        return ImportFileType::LIST;
    }

    public function supports(string $filename, array $header): bool
    {
        return isset($header[0]) && ListCsvReader::isSignature($header[0]);
    }

    public function countRows(string $filepath): int
    {
        return \count($this->listReader->read($filepath)->rows);
    }

    public function import(string $filepath, ImportBatch $batch): array
    {
        // See AbstractCsvImporter: the upserter outlives the batch in the worker process.
        $this->movieUpserter->reset();

        $export = $this->listReader->read($filepath);
        if ('' === $export->url) {
            // The link is the list's identity. Without it a re-import could not tell this
            // list from a new one, and every export would add a copy.
            throw new ImportException('La liste n\'a pas de lien Letterboxd : impossible de la reconnaître d\'un import à l\'autre.');
        }

        $user = $batch->getUser();
        $list = $this->lists->findOneByUserAndUrl($user, $export->url);
        if (null === $list) {
            $list = new MovieList($user, $export->url, $this->nameOf($export));
            $this->entityManager->persist($list);
        }

        $list->setName($this->nameOf($export))
            ->setDescription($export->description)
            ->setTags($this->tagsOf($export->tags))
            ->setCreatedOn($this->dateOf($export->date))
            ->markImported();

        // Keyed by object so a film named twice in one file counts as one entry.
        /** @var array<int, array{movie: Movie, position: int, note: ?string}> $named */
        $named = [];
        foreach ($export->rows as $rowNumber => $row) {
            try {
                [$movie, $position, $note] = $this->readRow($row, $rowNumber);
                $named[spl_object_id($movie)] = ['movie' => $movie, 'position' => $position, 'note' => $note];
                $batch->incrementRowsImported();
            } catch (ImportRowException $e) {
                $batch->incrementRowsFailed();
                $rowError = new ImportRowError($batch, $rowNumber, $row, $e->getMessage());
                $this->entityManager->persist($rowError);
                $batch->addRowError($rowError);
            }
        }

        $this->reconcile($list, array_values($named), $batch->getRowsFailed() > 0);

        $this->entityManager->flush();

        return array_values(array_map(static fn (array $entry) => (string) $entry['movie']->getId(), $named));
    }

    /**
     * Brings the stored entries in line with the file: updates what is still there, adds
     * what is new, and drops what the list no longer names.
     *
     * Updated in place rather than deleted and recreated. Doctrine runs inserts before
     * deletes inside a flush, so recreating a film that is still on the list would hit the
     * (list, movie) constraint against its own old row.
     *
     * Nothing is dropped when a row failed. A film whose link did not resolve this time —
     * boxd.it down, a network blip — is absent from $named without having left the list, and
     * reading that absence as a removal would delete it until the next import put it back.
     *
     * @param list<array{movie: Movie, position: int, note: ?string}> $named
     */
    private function reconcile(MovieList $list, array $named, bool $hadFailures): void
    {
        $existing = [];
        foreach ($list->getEntries() as $entry) {
            $existing[(string) $entry->getMovie()->getId()] = $entry;
        }

        $kept = [];
        foreach ($named as ['movie' => $movie, 'position' => $position, 'note' => $note]) {
            // Ids are minted in PHP when the entity is built, so even a film this very import
            // created has one to key on — it just will not match anything stored.
            $key = (string) $movie->getId();
            $entry = $existing[$key] ?? null;

            if (null === $entry) {
                $entry = new MovieListEntry($list, $movie, $position);
                $list->addEntry($entry);
                $this->entityManager->persist($entry);
            }

            $entry->setPosition($position)->setNote($note);
            $kept[$key] = true;
        }

        if ($hadFailures) {
            return;
        }

        foreach ($existing as $key => $entry) {
            if (!isset($kept[$key])) {
                // orphanRemoval on the collection is what turns this into a DELETE.
                $list->removeEntry($entry);
            }
        }
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{Movie, int, ?string}
     */
    private function readRow(array $row, int $rowNumber): array
    {
        $name = trim((string) ($row['Name'] ?? ''));
        $url = trim((string) ($row['URL'] ?? ''));
        if ('' === $name || '' === $url) {
            throw new ImportRowException('Colonnes "Name" et "URL" attendues.');
        }

        $slug = $this->slugResolver->resolve($url);
        if (null === $slug) {
            throw new ImportRowException(sprintf('Impossible de résoudre le film depuis l\'URL "%s".', $url));
        }

        $year = trim((string) ($row['Year'] ?? ''));
        $movie = $this->movieUpserter->upsert($slug, $name, ctype_digit($year) ? (int) $year : null);

        // The file's own numbering when it has one, the row's place otherwise: an unranked
        // list still has an order, and it is the one the file was written in.
        $position = trim((string) ($row['Position'] ?? ''));
        $note = trim((string) ($row['Description'] ?? ''));

        return [$movie, ctype_digit($position) ? (int) $position : $rowNumber, '' === $note ? null : $note];
    }

    /** A list always has a name on Letterboxd; an empty one here is a damaged file, not a list called "". */
    private function nameOf(ListExport $export): string
    {
        return '' !== $export->name ? mb_substr($export->name, 0, 500) : 'Liste sans titre';
    }

    /**
     * @return list<string>
     */
    private function tagsOf(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag) => '' !== $tag));
    }

    private function dateOf(string $date): ?\DateTimeImmutable
    {
        $parsed = '' !== $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        return false !== $parsed ? $parsed : null;
    }
}
