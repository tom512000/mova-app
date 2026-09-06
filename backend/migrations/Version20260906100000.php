<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TMDB franchises — what it calls collections: Jurassic Park, Indiana Jones, L'Âge de glace.
 *
 * Two tables rather than one. `franchise` is the saga; `franchise_film` is every film TMDB
 * lists in it, held whether or not the library owns that film, because "you have four of the
 * seven" is only worth reading if it can name the other three.
 *
 * Written by hand rather than taken from a schema diff: the diff also wanted to drop
 * _backup_game_session_guesses, which is not this migration's business and has never been
 * asked for.
 */
final class Version20260906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les sagas TMDB (franchise, franchise_film) et le lien depuis movie.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('franchise'),
            'Les sagas sont déjà en place.'
        );

        $this->addSql('CREATE TABLE franchise (id UUID NOT NULL, tmdb_id INT NOT NULL, name VARCHAR(200) NOT NULL, poster_path VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_franchise_tmdb_id ON franchise (tmdb_id)');

        $this->addSql('CREATE TABLE franchise_film (id UUID NOT NULL, franchise_id UUID NOT NULL, tmdb_id INT NOT NULL, title VARCHAR(500) NOT NULL, release_date DATE DEFAULT NULL, poster_path VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_franchise_film_franchise ON franchise_film (franchise_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_franchise_film_tmdb_id ON franchise_film (franchise_id, tmdb_id)');
        $this->addSql('ALTER TABLE franchise_film ADD CONSTRAINT fk_franchise_film_franchise FOREIGN KEY (franchise_id) REFERENCES franchise (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // SET NULL rather than CASCADE: dropping a franchise must never take the films out
        // of the library with it.
        $this->addSql('ALTER TABLE movie ADD franchise_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_movie_franchise ON movie (franchise_id)');
        $this->addSql('ALTER TABLE movie ADD CONSTRAINT fk_movie_franchise FOREIGN KEY (franchise_id) REFERENCES franchise (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movie DROP CONSTRAINT IF EXISTS fk_movie_franchise');
        $this->addSql('DROP INDEX IF EXISTS idx_movie_franchise');
        $this->addSql('ALTER TABLE movie DROP COLUMN IF EXISTS franchise_id');
        $this->addSql('DROP TABLE IF EXISTS franchise_film');
        $this->addSql('DROP TABLE IF EXISTS franchise');
    }
}
