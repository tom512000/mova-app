<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessImportBatchMessage;
use App\Repository\ImportBatchRepository;
use App\Service\Import\ImportOrchestrator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessImportBatchMessageHandler
{
    public function __construct(
        private readonly ImportBatchRepository $importBatchRepository,
        private readonly ImportOrchestrator $orchestrator,
    ) {
    }

    public function __invoke(ProcessImportBatchMessage $message): void
    {
        $batch = $this->importBatchRepository->find($message->importBatchId);
        if (null === $batch) {
            return;
        }

        $this->orchestrator->process($batch);
    }
}
