<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves series credits off the director role and onto the creator role.
 *
 * When series support landed, TMDB's `created_by` was filed under DIRECTOR so the clue and
 * comparison games could treat a series like a film without branching. The cost only showed
 * up later, in the stats: "most-watched directors" was counting whoever created a series.
 * Pierre Niney appeared there because he co-created Fiasco. He did not direct it.
 *
 * The rewrite is safe to scope by media_type. A series has no DIRECTOR credits of any other
 * provenance — episode directors live in TMDB's per-episode payload, which this app has
 * never fetched — so every director credit on a series is a creator that was mislabelled.
 * Films are not touched.
 */
final class Version20260829190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relabels series director credits as creator credits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE credit SET role = 'creator'
            WHERE role = 'director'
              AND movie_id IN (SELECT id FROM movie WHERE media_type = 'series')"
        );
    }

    public function down(Schema $schema): void
    {
        // Reversible without loss: the same scoping identifies exactly the rows up() moved,
        // since nothing else in the app writes a creator credit.
        $this->addSql(
            "UPDATE credit SET role = 'director'
            WHERE role = 'creator'
              AND movie_id IN (SELECT id FROM movie WHERE media_type = 'series')"
        );
    }
}
