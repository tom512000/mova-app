<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the hangman's letters to a game session.
 */
final class Version20260827122933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds game_session.letters, the letters played in a hangman run.';
    }

    public function up(Schema $schema): void
    {
        // The column is NOT NULL and runs already exist, so they need something to hold.
        // The default is dropped straight after: the entity always supplies its own [].
        $this->addSql("ALTER TABLE game_session ADD letters JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE game_session ALTER COLUMN letters DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_session DROP letters');
    }
}
