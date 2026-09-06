<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops _backup_game_session_guesses, the safety net taken before game_session.guesses was
 * converted from integer film ids to UUIDs.
 *
 * It has expired twice over. The conversion is verified — all sixty rows hold arrays of
 * exactly the same length before and after, so nothing was dropped on the way through — and
 * `movie.id` is now a uuid column, which means the integers held here cannot be resolved
 * back to a film at all. The table is not merely redundant, it is unreadable.
 *
 * IF EXISTS is not defensive habit: no migration ever created this table. It was made by
 * hand on one development database, so it is absent from a fresh install and from
 * production, and both must run this without failing.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime _backup_game_session_guesses, filet de sécurité de la conversion en UUID, devenu illisible.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS _backup_game_session_guesses');
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. There is no earlier state to go back to: the table was never
        // part of the schema this project builds, and its contents were integer ids that
        // stopped meaning anything the day movie.id became a uuid. Recreating it empty would
        // put back the shape of a safety net without the safety.
    }
}
