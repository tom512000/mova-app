<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a game session a board.
 *
 * The four games added alongside this migration split two ways. "L'accroche" and "Le décor"
 * hide a single film like the four before them and need nothing new. "Le duel" and "La
 * chronologie" do not: one plays on a pair, the other on five films at once, and neither
 * fits in the single `movie` relation the table has always had.
 *
 * So: `board` is what is on the table right now, and `plays` is every board already
 * resolved. Both stay empty for the six games that hide a film — a column no game of yours
 * has ever used costs one empty JSON array per row, which is cheaper than the alternative
 * of a second session table that would duplicate the mode, the status and the daily
 * constraint to hold two extra lists.
 *
 * The DEFAULT is scaffolding, not schema: NOT NULL has to be satisfied for rows that exist
 * already, and it is dropped again immediately so the column matches what Doctrine's
 * mapping describes and `doctrine:schema:validate` stays quiet.
 */
final class Version20260829145750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds board and plays to game_session, for the games that play on more than one film.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_session ADD board JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE game_session ADD plays JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE game_session ALTER COLUMN board DROP DEFAULT');
        $this->addSql('ALTER TABLE game_session ALTER COLUMN plays DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // The two games that use these columns cannot be played without them, so their runs
        // go rather than being left behind as sessions with an unreadable board.
        $this->addSql("DELETE FROM game_session WHERE game IN ('duel', 'timeline')");
        $this->addSql('ALTER TABLE game_session DROP board');
        $this->addSql('ALTER TABLE game_session DROP plays');
    }
}
