<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ImportFileType: string
{
    case DIARY = 'diary';
    case RATINGS = 'ratings';
    case WATCHED = 'watched';
    case REVIEWS = 'reviews';
    case WATCHLIST = 'watchlist';
    case PROFILE = 'profile';
    case LIST = 'list';

    /**
     * RatingsImporter/WatchedImporter only backfill a Watch when the movie has none
     * yet, so diary.csv (the most detailed source) must be fully processed first —
     * otherwise a race between files in the same upload could create a spurious
     * dateless Watch for a film diary.csv was about to describe properly.
     *
     * profile.csv goes last for a milder reason: its four favourites are films the library
     * almost always already holds, and letting the files that carry a title and a year land
     * first means it finds them instead of minting stubs named after their slugs.
     */
    public function importPriority(): int
    {
        return match ($this) {
            self::DIARY => 0,
            self::RATINGS => 1,
            self::WATCHED => 2,
            self::REVIEWS => 3,
            self::WATCHLIST => 4,
            self::PROFILE => 5,
            self::LIST => 6,
        };
    }
}
