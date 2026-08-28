<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a Movie row hold a series as well as a film.
 *
 * The unique index is the load-bearing change: TMDB numbers its film and series
 * catalogues independently, so tv/84958 (Loki) and movie/84958 are unrelated works
 * that both carry the id 84958. Keyed on tmdb_id alone, storing the second one would
 * fail on a constraint violation years from now with no obvious cause.
 */
final class Version20260829094512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media_type + series fields to movie; key the TMDB id per catalogue.';
    }

    public function up(Schema $schema): void
    {
        // Every row that exists today came from /movie/{id}, so the default backfills all
        // of them correctly. It stays on the column afterwards — a film is the overwhelming
        // majority case and MovieUpserter creates stubs long before anything knows better.
        $this->addSql("ALTER TABLE movie ADD media_type VARCHAR(20) NOT NULL DEFAULT 'movie'");
        $this->addSql('ALTER TABLE movie ADD season_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movie ADD episode_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movie ADD last_air_date DATE DEFAULT NULL');

        $this->addSql('DROP INDEX uniq_movie_tmdb_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_media_type_tmdb_id ON movie (media_type, tmdb_id)');
    }

    public function down(Schema $schema): void
    {
        // A film and a series sharing a TMDB id can coexist above but not below, so the
        // narrower index has to be rebuilt on a table that no longer holds series.
        $this->addSql("DELETE FROM movie WHERE media_type = 'series'");

        $this->addSql('DROP INDEX uniq_movie_media_type_tmdb_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_tmdb_id ON movie (tmdb_id)');

        $this->addSql('ALTER TABLE movie DROP media_type');
        $this->addSql('ALTER TABLE movie DROP season_count');
        $this->addSql('ALTER TABLE movie DROP episode_count');
        $this->addSql('ALTER TABLE movie DROP last_air_date');
    }
}
