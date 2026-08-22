<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260822160513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen credit.character_name to TEXT: TMDB can concatenate many roles for one person on an ensemble/animated film past 255 chars.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit ALTER character_name TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit ALTER character_name TYPE VARCHAR(255)');
    }
}
