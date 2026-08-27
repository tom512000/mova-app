<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A second game shares the board, so a session now says which one it belongs to. The unique
 * index moves with it: "one run per day" has to mean one per day *per game*, otherwise
 * playing one would lock the other out until midnight.
 */
final class Version20260826204222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distingue les deux jeux (indices / comparaison) sur game_session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_game_session_user_mode_status');
        $this->addSql('DROP INDEX uniq_game_session_daily');
        // Added with a default so existing rows can adopt it, then dropped: every row
        // written before this migration belongs to the clue game, the only one there was.
        $this->addSql("ALTER TABLE game_session ADD game VARCHAR(20) NOT NULL DEFAULT 'clue'");
        $this->addSql('ALTER TABLE game_session ALTER COLUMN game DROP DEFAULT');
        $this->addSql('CREATE INDEX idx_game_session_user_game_mode_status ON game_session (user_id, game, mode, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_session_daily ON game_session (user_id, game, mode, puzzle_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_game_session_user_game_mode_status');
        $this->addSql('DROP INDEX uniq_game_session_daily');
        $this->addSql('ALTER TABLE game_session DROP game');
        $this->addSql('CREATE INDEX idx_game_session_user_mode_status ON game_session (user_id, mode, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_session_daily ON game_session (user_id, mode, puzzle_date)');
    }
}
