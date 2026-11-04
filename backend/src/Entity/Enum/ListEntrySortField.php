<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How one list's films can be ordered.
 *
 * POSITION is the default and has no equivalent in the library: it is the order the list's
 * owner gave it, and for a ranked list that order *is* the list.
 */
enum ListEntrySortField: string
{
    case POSITION = 'position';
    case TITLE = 'title';
    case YEAR = 'year';
    case RATING = 'rating';
    case RUNTIME = 'runtime';
    case RANDOM = 'random';

    /** Same convention as MovieSortField: orders read upwards, judgements read best first. */
    public function defaultsToDescending(): bool
    {
        return match ($this) {
            self::POSITION, self::TITLE, self::YEAR, self::RANDOM => false,
            self::RATING, self::RUNTIME => true,
        };
    }
}
