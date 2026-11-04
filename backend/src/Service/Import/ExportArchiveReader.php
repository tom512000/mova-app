<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\ImportException;

/**
 * Opens an uploaded Letterboxd export, extracts what the importers may read, and accounts
 * for everything else.
 *
 * The second half is the reason this class exists. The archive used to be filtered on the
 * way out — non-CSVs and subfolders dropped before anything could report them — so the
 * upload could only ever name the root CSVs no importer recognised. On a real export that
 * was one file out of fifteen left unread: comments.csv was announced, and likes/, lists/,
 * deleted/ and orphaned/ vanished without a word.
 */
final class ExportArchiveReader
{
    /**
     * Folders left out of the inventory altogether, not merely left unread.
     *
     * deleted/ holds what the account owner removed on Letterboxd themselves. Importing it
     * would undo their own decision, so there is no importer to write for it, and a line per
     * file would only keep asking a question that already has its answer.
     *
     * orphaned/ is undocumented by Letterboxd. Its files mirror the root diary/reviews/
     * comments columns, and the likeliest reading is entries whose film has since left the
     * Letterboxd catalogue — a film with no page there has no slug to resolve and nothing
     * for TMDB enrichment to start from, so it would never become a usable library entry.
     */
    private const HIDDEN_FOLDERS = ['deleted/', 'orphaned/'];

    /**
     * Subfolders whose files are offered to the importers, like the root ones.
     *
     * lists/ holds one file per list, named after the list's slug. Only this folder, and
     * only one level deep: deleted/lists/ holds the lists their owner removed, and is hidden
     * with the rest of deleted/.
     */
    private const EXTRACTED_FOLDERS = ['lists/'];

    /**
     * @return list<ExportArchiveEntry> every file in the archive, root first, then by path
     *
     * @throws ImportException when the archive cannot be opened at all
     */
    public function read(string $zipPath, string $sessionDir): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new ImportException('Impossible de lire l\'archive ZIP.');
        }

        $entries = [];
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entryName = $zip->getNameIndex($i);
                // A trailing slash is a folder, not a file: its contents get their own entries.
                if (false === $entryName || str_ends_with($entryName, '/') || $this->isHidden($entryName)) {
                    continue;
                }

                $entries[] = $this->entryFor($zipPath, $entryName, $sessionDir);
            }
        } finally {
            $zip->close();
        }

        usort($entries, static fn (ExportArchiveEntry $a, ExportArchiveEntry $b) => [substr_count($a->path, '/'), $a->path] <=> [substr_count($b->path, '/'), $b->path]);

        return $entries;
    }

    private function isHidden(string $entryName): bool
    {
        $path = ltrim($entryName, '/');
        foreach (self::HIDDEN_FOLDERS as $folder) {
            if (str_starts_with($path, $folder)) {
                return true;
            }
        }

        return false;
    }

    private function entryFor(string $zipPath, string $entryName, string $sessionDir): ExportArchiveEntry
    {
        $path = trim($entryName, '/');
        $inArchive = sprintf('zip://%s#%s', $zipPath, $entryName);

        if (!str_ends_with(strtolower($path), '.csv')) {
            return ExportArchiveEntry::ignored($path, null, 'Pas un fichier CSV.');
        }

        // Root-level files, plus the folders listed in EXTRACTED_FOLDERS. A real Letterboxd
        // export ships subfolders that reuse the root basenames (deleted/diary.csv,
        // likes/films.csv…), so nothing is ever extracted by basename alone: a subfolder file
        // keeps its folder on disk, which is what stops a list its owner happened to call
        // "diary" from overwriting the real diary.csv. The other subfolders are still listed,
        // and still readable from inside the archive, so the inventory can show what they hold.
        $folder = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '';
        if ('' !== $folder && !\in_array($folder, self::EXTRACTED_FOLDERS, true)) {
            return ExportArchiveEntry::ignored($path, $inArchive, sprintf('Sous-dossier « %s », que l\'import ne lit pas encore.', $folder));
        }

        $targetDir = rtrim(sprintf('%s/%s', $sessionDir, $folder), '/');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new ImportException(sprintf('Impossible de créer le dossier « %s ».', $folder));
        }

        // basename() even though $folder was checked: the folder test is a whitelist on the
        // prefix, and this is what keeps a crafted "lists/../x.csv" from climbing out of it.
        $destination = sprintf('%s/%s', $targetDir, basename($path));
        if (!copy($inArchive, $destination)) {
            throw new ImportException(sprintf('Impossible d\'extraire « %s » de l\'archive.', $path));
        }

        return ExportArchiveEntry::extracted($path, $destination);
    }
}
