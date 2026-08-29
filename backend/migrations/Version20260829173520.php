<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives profile.csv somewhere to land.
 *
 * Two tables rather than columns on app_user, because these are two different identities.
 * app_user is who signs in here; letterboxd_profile is a snapshot of somebody's Letterboxd
 * page, read from a file and replaced wholesale on the next import. Keeping them apart means
 * an import can overwrite one without ever touching a credential.
 *
 * favourite_film is a join row rather than a plain many-to-many because the order is the
 * point: these are four numbered slots somebody arranged by hand, not a set. Hence the
 * unique index over (profile, position) — two films can never claim the same slot.
 */
final class Version20260829173520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds letterboxd_profile and favourite_film, for what profile.csv carries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE favourite_film (position INT NOT NULL, id UUID NOT NULL, profile_id UUID NOT NULL, movie_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2656B583CCFA12B8 ON favourite_film (profile_id)');
        $this->addSql('CREATE INDEX IDX_2656B5838F93B6FC ON favourite_film (movie_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_favourite_film_slot ON favourite_film (profile_id, position)');
        $this->addSql('CREATE TABLE letterboxd_profile (username VARCHAR(100) DEFAULT NULL, given_name VARCHAR(100) DEFAULT NULL, family_name VARCHAR(100) DEFAULT NULL, location VARCHAR(150) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, bio TEXT DEFAULT NULL, pronoun VARCHAR(50) DEFAULT NULL, joined_on DATE DEFAULT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3DAABCF9A76ED395 ON letterboxd_profile (user_id)');
        $this->addSql('ALTER TABLE favourite_film ADD CONSTRAINT FK_2656B583CCFA12B8 FOREIGN KEY (profile_id) REFERENCES letterboxd_profile (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE favourite_film ADD CONSTRAINT FK_2656B5838F93B6FC FOREIGN KEY (movie_id) REFERENCES movie (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE letterboxd_profile ADD CONSTRAINT FK_3DAABCF9A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favourite_film DROP CONSTRAINT FK_2656B583CCFA12B8');
        $this->addSql('ALTER TABLE favourite_film DROP CONSTRAINT FK_2656B5838F93B6FC');
        $this->addSql('ALTER TABLE letterboxd_profile DROP CONSTRAINT FK_3DAABCF9A76ED395');
        $this->addSql('DROP TABLE favourite_film');
        $this->addSql('DROP TABLE letterboxd_profile');
    }
}
