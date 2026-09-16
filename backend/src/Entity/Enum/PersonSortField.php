<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How the directory of people is ordered.
 *
 * Deliberately not MovieSortField's list under another name: a person has no release year
 * and no runtime, and what a reader wants from a name is how much of them they have seen.
 * WORKS is the count of works watched, RECENT the last evening spent with one of them —
 * two different questions that "watched" alone would blur.
 */
enum PersonSortField: string
{
    case NAME = 'name';
    case WORKS = 'works';
    case RATING = 'rating';
    case RECENT = 'recent';
    case RANDOM = 'random';

    /**
     * The direction a reader expects the first time they pick this field: the alphabet
     * reads upwards, every tally reads "most first".
     */
    public function defaultsToDescending(): bool
    {
        return match ($this) {
            self::NAME, self::RANDOM => false,
            self::WORKS, self::RATING, self::RECENT => true,
        };
    }
}
