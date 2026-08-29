<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum WatchSource: string
{
    case CSV_IMPORT = 'csv_import';
    case RSS_SYNC = 'rss_sync';
    case MANUAL = 'manual';

    /**
     * A viewing nobody declared, inferred from ratings.csv changing its mind about a film.
     *
     * Letterboxd only writes a diary entry when you go through "Log this film". Re-rating a
     * film from its page updates the single ratings.csv row in place: the rating changes and
     * the Date — which is when the rating was last set — moves. The previous values are gone
     * from the export, and the importer used to overwrite them, so a second opinion left no
     * trace at all.
     *
     * A date that has moved forward is that second opinion. It is recorded as its own viewing
     * rather than as a correction of the first, because the application already treats that
     * date as a viewing date: keeping both is not a stronger claim than keeping only the
     * newest, it is simply less forgetful.
     */
    case CSV_RERATING = 'csv_rerating';

    /**
     * Whether this viewing was worked out rather than stated.
     *
     * The distinction is worth keeping visible: re-rating a film usually means having watched
     * it again, but a change of heart with no second viewing looks exactly the same in the
     * export. Screens that show a rewatch should say which kind they are showing.
     */
    public function isDeduced(): bool
    {
        return self::CSV_RERATING === $this;
    }
}
