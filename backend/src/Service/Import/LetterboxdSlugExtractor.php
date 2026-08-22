<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Letterboxd export CSVs never expose a stable film id, only a "Letterboxd URI"
 * such as https://letterboxd.com/username/film/dune-part-two/ (diary/ratings/watched)
 * or https://letterboxd.com/username/film/dune-part-two/2/ for a repeated diary entry
 * (trailing digit = nth watch, not part of the film identity).
 *
 * The slug ("dune-part-two") is stable across the user's whole account and matches
 * the public film page (letterboxd.com/film/dune-part-two/), so it's used as the
 * anchor for idempotent Movie upserts.
 */
final class LetterboxdSlugExtractor
{
    public function extract(string $letterboxdUri): ?string
    {
        $path = trim((string) parse_url($letterboxdUri, PHP_URL_PATH), '/');
        if ('' === $path) {
            return null;
        }

        $segments = explode('/', $path);
        $filmIndex = array_search('film', $segments, true);
        if (false === $filmIndex || !isset($segments[$filmIndex + 1])) {
            return null;
        }

        $slug = $segments[$filmIndex + 1];

        return '' !== $slug ? $slug : null;
    }
}
