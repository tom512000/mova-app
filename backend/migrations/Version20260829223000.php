<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records the French theatrical release date alongside TMDB's primary one.
 *
 * "Vus à leur sortie" measured its gap from release_date, which is the film's *primary*
 * release and not always the French one. Measured against TMDB's per-country data on the
 * 29 films seen within 90 days of that date: 21 were identical, 4 had no French theatrical
 * date at all (straight to streaming), and 4 opened here later — one of which belongs in
 * the month-long window and was excluded, and one of which is shown as J+29 when the truth
 * is J+8.
 *
 * A separate column rather than a correction of release_date, because the two answer
 * different questions. release_date feeds release_year, the sort orders and the timeline
 * game; shifting a December film into January because France saw it late would be wrong in
 * all three. Nothing but the release-window stat reads this one.
 *
 * Left null here. Enrichment fills it from now on, and app:tmdb:backfill-french-releases
 * catches up the films already in the library — the column has no value this migration
 * could compute on its own.
 */
final class Version20260829223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds movie.french_release_date for the release-window statistic.';
    }

    public function up(Schema $schema): void
    {
        // No DC2Type comment: DBAL 4 no longer writes them, and adding one puts the schema
        // permanently out of sync with the mapping.
        $this->addSql('ALTER TABLE movie ADD french_release_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movie DROP french_release_date');
    }
}
