<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

interface LetterboxdFilmPageResolverInterface
{
    /**
     * @return array{tmdbId: int|null, imdbId: string|null}
     */
    public function resolve(string $slug): array;
}
