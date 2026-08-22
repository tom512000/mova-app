<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\Letterboxd\BoxdItResolverInterface;

/**
 * Resolves the Letterboxd film slug referenced by a CSV row's "Letterboxd URI"
 * column. In practice that column is always a `boxd.it/<code>` short link (see
 * BoxdItResolver) rather than a full film URL, so this follows the redirect first;
 * it also accepts an already-full letterboxd.com/film/<slug>/ URL for robustness
 * in case a future export format changes.
 */
final class FilmSlugResolver
{
    public function __construct(
        private readonly BoxdItResolverInterface $boxdItResolver,
        private readonly LetterboxdSlugExtractor $slugExtractor,
    ) {
    }

    public function resolve(string $letterboxdUri): ?string
    {
        $host = parse_url($letterboxdUri, PHP_URL_HOST);

        if ('boxd.it' === $host) {
            $code = trim((string) parse_url($letterboxdUri, PHP_URL_PATH), '/');
            $resolvedUrl = '' !== $code ? $this->boxdItResolver->resolve($code) : null;

            return null !== $resolvedUrl ? $this->slugExtractor->extract($resolvedUrl) : null;
        }

        return $this->slugExtractor->extract($letterboxdUri);
    }
}
