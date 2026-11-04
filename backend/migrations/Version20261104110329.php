<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Letterboxd lists: one row per list, one per film on it.
 *
 * **Keyed on the list's boxd.it link, per account.** It is the only identifier the export
 * carries, and unlike the name it survives a rename — keying on the name would turn every
 * renamed list into a second copy with the first one left behind.
 *
 * **Unique on (list, movie), not on (list, position).** Letterboxd does not put a film on a
 * list twice, so the first is a fact. The second would be one too, but a re-import that
 * reorders a list moves positions between rows that all still exist, and a constraint on
 * position would fail halfway through a swap. The (list, position) index stays, unenforced,
 * because it is the order every read of the list asks for.
 *
 * Both cascade: an account taking its lists with it, a list its entries, and a film dropped
 * by a corrected slug its place on every list.
 */
final class Version20261104110329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Listes Letterboxd : une ligne par liste et par film de la liste.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('movie_list'), 'Les listes sont déjà en place.');

        $this->addSql('CREATE TABLE movie_list (letterboxd_url VARCHAR(255) NOT NULL, name VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, tags JSON NOT NULL, created_on DATE DEFAULT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_movie_list_user ON movie_list (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_list_user_url ON movie_list (user_id, letterboxd_url)');

        $this->addSql('CREATE TABLE movie_list_entry (position INT NOT NULL, note TEXT DEFAULT NULL, id UUID NOT NULL, list_id UUID NOT NULL, movie_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1B42794F3DAE168B ON movie_list_entry (list_id)');
        $this->addSql('CREATE INDEX idx_movie_list_entry_list_position ON movie_list_entry (list_id, position)');
        $this->addSql('CREATE INDEX idx_movie_list_entry_movie ON movie_list_entry (movie_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_list_entry_list_movie ON movie_list_entry (list_id, movie_id)');

        $this->addSql('ALTER TABLE movie_list ADD CONSTRAINT FK_B7AED915A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE movie_list_entry ADD CONSTRAINT FK_1B42794F3DAE168B FOREIGN KEY (list_id) REFERENCES movie_list (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE movie_list_entry ADD CONSTRAINT FK_1B42794F8F93B6FC FOREIGN KEY (movie_id) REFERENCES movie (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // The child first: its foreign key points at the parent.
        $this->addSql('DROP TABLE IF EXISTS movie_list_entry');
        $this->addSql('DROP TABLE IF EXISTS movie_list');
    }
}
