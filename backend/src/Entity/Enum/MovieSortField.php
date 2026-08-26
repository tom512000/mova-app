<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum MovieSortField: string
{
    case TITLE = 'title';
    case RATING = 'rating';
    case YEAR = 'year';
    case WATCHED = 'watched';
    case ADDED = 'added';
    case RUNTIME = 'runtime';
    case RANDOM = 'random';

    /**
     * The direction a reader expects the first time they pick this field: alphabetical and
     * chronological-by-release read upwards, everything else reads "best/newest first".
     */
    public function defaultsToDescending(): bool
    {
        return match ($this) {
            self::TITLE, self::YEAR, self::RANDOM => false,
            self::RATING, self::WATCHED, self::ADDED, self::RUNTIME => true,
        };
    }
}
