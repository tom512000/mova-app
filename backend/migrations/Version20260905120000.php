<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The table Symfony's PdoSessionHandler keeps sessions in, used by the prod environment.
 *
 * Created here rather than by the handler's own createTable() so it is versioned with the
 * rest of the schema and exists before the first request rather than during it. Development
 * still stores sessions as files and never reads this table — it is created everywhere all
 * the same, because a schema that differs between environments is one that gets tested in
 * only one of them.
 *
 * Column names and types are the handler's defaults, not a choice: it builds its own SQL
 * against them.
 */
final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the sessions table for PdoSessionHandler.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('sessions'), 'The sessions table is already there.');

        $this->addSql('CREATE TABLE sessions (
            sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
            sess_data BYTEA NOT NULL,
            sess_lifetime INTEGER NOT NULL,
            sess_time INTEGER NOT NULL
        )');

        // Garbage collection deletes on sess_lifetime + sess_time, and it runs while people
        // are using the site. Without this it is a sequential scan of every live session.
        $this->addSql('CREATE INDEX idx_sessions_lifetime ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sessions');
    }
}
