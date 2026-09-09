<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits "the day the rating was logged" out of "the day the film was watched".
 *
 * They were one column, and Letterboxd exports them as two different facts. ratings.csv
 * dates the rating; watched.csv dates the film being marked as watched. On a real export
 * they disagree for 122 films out of 738 — median two weeks apart, up to sixteen months —
 * because rating a stack of films in one sitting stamps them all with that evening. The
 * calendar showed eight films on the 25th of July that had been watched across six
 * different days.
 *
 * The backfill copies watched_date into the new column rather than leaving it null, and that
 * is the conservative reading rather than the lazy one: every existing row was created from
 * ratings.csv, so its watched_date *is* the rating date. Leaving them null would make the
 * next import see "no rating date on record", read the export's date as a change, and mint a
 * re-rating for every film in the library.
 *
 * Existing watched_date values stay as they are. Only a fresh import can improve them, since
 * the better dates live in a file this database never read.
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sépare la date de notation (ratings.csv) de la date de visionnage (watched.csv).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('watch')->hasColumn('rated_on'),
            'La date de notation est déjà séparée.'
        );

        $this->addSql('ALTER TABLE watch ADD rated_on DATE DEFAULT NULL');

        // Every row predating this migration came from ratings.csv, so its watched_date is
        // the rating date. See the class comment for why this cannot be left null.
        $this->addSql('UPDATE watch SET rated_on = watched_date WHERE watched_date IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watch DROP COLUMN IF EXISTS rated_on');
    }
}
