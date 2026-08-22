<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Enum\ImportStatus;
use App\Entity\ImportBatch;
use App\Message\EnrichMovieMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportOrchestrator
{
    public function __construct(
        private readonly ImporterRegistry $importerRegistry,
        private readonly CsvReader $csvReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ImportBatch $batch): void
    {
        $batch->setStatus(ImportStatus::PROCESSING);
        $this->entityManager->flush();

        try {
            $importer = $this->importerRegistry->resolve($batch->getFilename(), $batch->getStoredPath());
            $batch->setRowsTotal($this->csvReader->countDataRows($batch->getStoredPath()));

            $touchedMovieIds = $importer->import($batch->getStoredPath(), $batch);

            $batch->setStatus($batch->getRowsFailed() > 0 ? ImportStatus::COMPLETED_WITH_ERRORS : ImportStatus::COMPLETED);
            $batch->markFinished();
            $this->entityManager->flush();

            foreach ($touchedMovieIds as $movieId) {
                $this->messageBus->dispatch(new EnrichMovieMessage($movieId));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Import batch #{id} failed: {message}', [
                'id' => $batch->getId(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $batch->setStatus(ImportStatus::FAILED);
            $batch->markFinished();
            $this->entityManager->flush();
        }
    }
}
