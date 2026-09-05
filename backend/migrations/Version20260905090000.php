<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unflags the re-ratings that were recorded as rewatches.
 *
 * RatingsImporter used to set is_rewatch on every row it minted from a moved rating date.
 * That date moving usually means a second viewing, but it also moves when the note is
 * revised after somebody else's opinion — and ratings.csv cannot tell the two apart. The
 * flag is now left alone at import, so the rows already written have to be corrected too,
 * or the dashboard keeps counting an evening that may never have happened.
 *
 * Only the rows this importer invented are touched. A rewatch Letterboxd actually declared
 * arrives through diary.csv, reviews.csv or the RSS feed and carries a different source.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear is_rewatch on watches deduced from a moved rating date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE watch SET is_rewatch = false WHERE source = 'csv_rerating' AND is_rewatch = true");
    }

    public function down(Schema $schema): void
    {
        // Reversible without guessing: every csv_rerating row carried the flag before this ran.
        $this->addSql("UPDATE watch SET is_rewatch = true WHERE source = 'csv_rerating'");
    }
}
