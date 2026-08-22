<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ImportBatch;
use App\Exception\ImportException;
use App\Mapper\ImportBatchMapper;
use App\Message\ProcessImportBatchMessage;
use App\Repository\ImportBatchRepository;
use App\Service\Import\ImporterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/import')]
final class ImportController
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly ImporterRegistry $importerRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ImportBatchRepository $importBatchRepository,
        private readonly ImportBatchMapper $importBatchMapper,
        #[Autowire('%kernel.project_dir%/var/imports')]
        private readonly string $importStorageDir,
    ) {
    }

    #[Route('/letterboxd', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
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

        $csvFiles = 'zip' === $extension
            ? $this->extractZip($file, $sessionDir)
            : [$file->getClientOriginalName() => $file->move($sessionDir, $file->getClientOriginalName())->getPathname()];

        $batches = [];
        $unsupported = [];

        foreach ($csvFiles as $originalName => $path) {
            try {
                $importer = $this->importerRegistry->resolve($originalName, $path);
            } catch (ImportException) {
                $unsupported[] = $originalName;
                continue;
            }

            $batch = new ImportBatch($originalName, $path, $importer->getFileType());
            $this->entityManager->persist($batch);
            $batches[] = $batch;
        }

        $this->entityManager->flush();

        // Dispatched in a fixed order (diary before ratings/watched) so a single sequential
        // worker (see docker-compose backend-worker) processes them in the order the
        // backfill logic in RatingsImporter/WatchedImporter depends on.
        usort($batches, static fn (ImportBatch $a, ImportBatch $b) => $a->getFileType()->importPriority() <=> $b->getFileType()->importPriority());

        foreach ($batches as $batch) {
            $this->messageBus->dispatch(new ProcessImportBatchMessage($batch->getId()));
        }

        return new JsonResponse([
            'batches' => array_map($this->importBatchMapper->toDto(...), $batches),
            'unsupportedFiles' => $unsupported,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $batch = $this->importBatchRepository->find($id);
        if (null === $batch) {
            return new JsonResponse(['error' => 'Import introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->importBatchMapper->toDto($batch));
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $batches = $this->importBatchRepository->findBy([], ['createdAt' => 'DESC'], 50);

        return new JsonResponse(array_map($this->importBatchMapper->toDto(...), $batches));
    }

    /**
     * @return array<string, string> original filename => extracted absolute path
     */
    private function extractZip(UploadedFile $file, string $sessionDir): array
    {
        $zipPath = $file->move($sessionDir, 'export.zip')->getPathname();

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new ImportException('Impossible de lire l\'archive ZIP.');
        }

        $extracted = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entryName = $zip->getNameIndex($i);
            if (null === $entryName || !str_ends_with(strtolower($entryName), '.csv')) {
                continue;
            }

            // Root-level files only: a real Letterboxd export also ships deleted/, orphaned/,
            // likes/ and lists/ subfolders that reuse the same basenames (e.g. deleted/diary.csv,
            // orphaned/reviews.csv, likes/films.csv) — extracting by basename alone would let
            // those silently overwrite the real root diary.csv/ratings.csv/reviews.csv/watched.csv.
            if (str_contains(trim($entryName, '/'), '/')) {
                continue;
            }

            $basename = basename($entryName);
            $destination = sprintf('%s/%s', $sessionDir, $basename);
            copy(sprintf('zip://%s#%s', $zipPath, $entryName), $destination);
            $extracted[$basename] = $destination;
        }

        $zip->close();

        return $extracted;
    }
}
