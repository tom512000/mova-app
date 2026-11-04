<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\ImportFileReportDto;
use App\Entity\Enum\ImportFileStatus;
use App\Entity\ImportBatch;
use App\Exception\ImportException;
use App\Mapper\ImportBatchMapper;
use App\Message\ProcessImportBatchMessage;
use App\Repository\ImportBatchRepository;
use App\Service\Import\CsvReader;
use App\Service\Import\ExportArchiveEntry;
use App\Service\Import\ExportArchiveReader;
use App\Service\Import\ImporterInterface;
use App\Service\Import\ImporterRegistry;
use App\Service\Profile\ViewedProfileResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/import')]
final class ImportController
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly ImporterRegistry $importerRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ImportBatchRepository $importBatchRepository,
        private readonly ImportBatchMapper $importBatchMapper,
        private readonly ExportArchiveReader $archiveReader,
        private readonly CsvReader $csvReader,
        #[Autowire('%kernel.project_dir%/var/imports')]
        private readonly string $importStorageDir,
    ) {
    }

    #[Route('/letterboxd', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        // Deliberately getAuthenticatedUser(), not getViewedUser(): importing into a profile
        // that was merely shared with you must be impossible, even with a forged profileId.
        $user = $this->profileResolver->getAuthenticatedUser();

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Aucun fichier valide reçu (champ "file" attendu).'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return new JsonResponse(['error' => 'Fichier trop volumineux (limite : 100 Mo).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!\in_array($extension, ['csv', 'zip'], true)) {
            return new JsonResponse(['error' => 'Seuls les fichiers .csv ou .zip (export complet) sont acceptés.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sessionDir = sprintf('%s/%s', rtrim($this->importStorageDir, '/'), Uuid::v4()->toRfc4122());
        if (!mkdir($sessionDir, 0775, true) && !is_dir($sessionDir)) {
            return new JsonResponse(['error' => 'Impossible de créer le répertoire de stockage temporaire.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $entries = 'zip' === $extension
                ? $this->archiveReader->read($file->move($sessionDir, 'export.zip')->getPathname(), $sessionDir)
                : [ExportArchiveEntry::extracted(
                    $file->getClientOriginalName(),
                    $file->move($sessionDir, $file->getClientOriginalName())->getPathname()
                )];
        } catch (ImportException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $batches = [];
        $files = [];

        foreach ($entries as $entry) {
            if (null === $entry->storedPath) {
                $files[] = $this->report($entry, ImportFileStatus::IGNORED, null, $entry->ignoredBecause);
                continue;
            }

            try {
                $importer = $this->importerRegistry->resolve($entry->path, $entry->storedPath);
            } catch (ImportException) {
                $files[] = $this->report($entry, ImportFileStatus::UNSUPPORTED, null, 'Aucun importeur ne reconnaît encore ce fichier.');
                continue;
            }

            $batch = new ImportBatch($user, $entry->path, $entry->storedPath, $importer->getFileType());
            $this->entityManager->persist($batch);
            $batches[] = $batch;
            $files[] = $this->report($entry, ImportFileStatus::QUEUED, $importer, null);
        }

        $this->entityManager->flush();

        // Dispatched in a fixed order (diary before ratings/watched) so a single sequential
        // worker (see docker-compose backend-worker) processes them in the order the
        // backfill logic in RatingsImporter/WatchedImporter depends on.
        usort($batches, static fn (ImportBatch $a, ImportBatch $b) => $a->getFileType()->importPriority() <=> $b->getFileType()->importPriority());

        foreach ($batches as $batch) {
            $this->messageBus->dispatch(new ProcessImportBatchMessage((string) $batch->getId()));
        }

        return new JsonResponse([
            'batches' => array_map($this->importBatchMapper->toDto(...), $batches),
            // Every file the upload contained, read or not — not just the ones that failed
            // to match. See ExportArchiveReader for what used to go missing.
            'files' => $files,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function show(string $id): JsonResponse
    {
        $batch = $this->importBatchRepository->findOneOwnedBy($this->profileResolver->getAuthenticatedUser(), $id);
        if (null === $batch) {
            return new JsonResponse(['error' => 'Import introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->importBatchMapper->toDto($batch));
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $batches = $this->importBatchRepository->findRecentForUser($this->profileResolver->getAuthenticatedUser());

        return new JsonResponse(array_map($this->importBatchMapper->toDto(...), $batches));
    }

    /**
     * A CSV that cannot be read still gets its line, with no columns: the inventory is
     * there to account for every file, and a file it could not open is one more thing to
     * account for rather than a reason to fail the upload.
     */
    private function report(ExportArchiveEntry $entry, ImportFileStatus $status, ?ImporterInterface $importer, ?string $reason): ImportFileReportDto
    {
        $columns = [];
        $rowCount = null;

        if (null !== $entry->readablePath) {
            try {
                $columns = array_values(array_filter($this->csvReader->readHeader($entry->readablePath), static fn (string $column) => '' !== $column));
                // The importer's own count when one took the file, so the inventory and the
                // progress bar agree: a list's films, not its lines.
                $rowCount = null !== $importer
                    ? $importer->countRows($entry->readablePath)
                    : $this->csvReader->countDataRows($entry->readablePath);
            } catch (ImportException) {
                $reason ??= 'Fichier illisible.';
            }
        }

        return new ImportFileReportDto($entry->path, $status, $importer?->getFileType(), $reason, $columns, $rowCount);
    }
}
