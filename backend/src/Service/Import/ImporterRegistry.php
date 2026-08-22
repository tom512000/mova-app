<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\ImportException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the right Importer for an uploaded file. Adding support for a new
 * Letterboxd export file only requires a new class implementing ImporterInterface
 * (auto-tagged via the `app.importer` _instanceof rule in services.yaml) — nothing
 * here needs to change.
 */
final class ImporterRegistry
{
    /** @var ImporterInterface[] */
    private readonly array $importers;

    public function __construct(
        #[AutowireIterator('app.importer')]
        iterable $importers,
        private readonly CsvReader $csvReader,
    ) {
        $this->importers = iterator_to_array($importers, false);
    }

    public function resolve(string $filename, string $filepath): ImporterInterface
    {
        $header = $this->csvReader->readHeader($filepath);

        foreach ($this->importers as $importer) {
            if ($importer->supports($filename, $header)) {
                return $importer;
            }
        }

        throw new ImportException(sprintf(
            'Type de fichier non reconnu pour "%s" (colonnes : %s).',
            $filename,
            implode(', ', $header)
        ));
    }
}
