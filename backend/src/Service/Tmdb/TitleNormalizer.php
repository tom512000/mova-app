<?php

declare(strict_types=1);

namespace App\Service\Tmdb;

final class TitleNormalizer
{
    private static ?\Transliterator $transliterator = null;

    public function normalize(string $title): string
    {
        // ext-intl's Transliterator is used instead of iconv's TRANSLIT (notoriously
        // inconsistent across platforms/builds) for reliable accent stripping.
        self::$transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');
        $title = self::$transliterator?->transliterate($title) ?? $title;

        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * Similarity score between 0.0 (nothing in common) and 1.0 (identical after normalization).
     */
    public function similarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ('' === $a || '' === $b) {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
