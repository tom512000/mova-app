<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Folds TMDB's two compound television genres into the film vocabulary.
 *
 * TMDB keeps a separate genre list for series, and two of its entries bundle concepts the
 * film list keeps apart. The library was therefore counting the same idea twice under two
 * names: "Science-Fiction" (186 works) and "Science-Fiction & Fantastique" (8) sat as
 * neighbours in the genre stats, and neither figure meant what it said.
 *
 *   10759 Action & Adventure            → 28 Action          + 12 Aventure
 *   10765 Science-Fiction & Fantastique → 878 Science-Fiction + 14 Fantastique
 *
 * TvGenreVocabulary now performs the same split at enrichment time, so nothing writes those
 * two ids any more. This migration deals with what is already stored: 11 series, 17 links.
 * Films are untouched — the compound ids only ever come off the /tv endpoint.
 *
 * The genre rows themselves are dropped at the end, which cascades onto movie_genre. The
 * fixed UUIDs below are only used to create a target genre a database happens not to have
 * yet, and only when the compound genre it replaces is present.
 */
final class Version20260829213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merges the compound TV genres into their film-vocabulary halves.';
    }

    public function up(Schema $schema): void
    {
        // A library whose only Action came from a series has no Action row to move the links
        // onto. Guarded both ways: nothing is created unless the compound genre it stands in
        // for is actually present, and ON CONFLICT covers the tmdb_id and name constraints.
        foreach ([
            [28, 'Action', '01a04ee3-8220-797d-a843-6a7b163b757c', 10759],
            [12, 'Aventure', '01a04ee3-8220-7be1-a843-6a7b16f366a0', 10759],
            [878, 'Science-Fiction', '01a04ee3-8220-7ca5-a843-6a7b1709f6f0', 10765],
            [14, 'Fantastique', '01a04ee3-8220-7cbd-a843-6a7b17a6f1cb', 10765],
        ] as [$tmdbId, $name, $uuid, $replaces]) {
            $this->addSql(
                "INSERT INTO genre (id, tmdb_id, name)
                 SELECT '{$uuid}'::uuid, {$tmdbId}, '{$name}'
                 WHERE EXISTS (SELECT 1 FROM genre WHERE tmdb_id = {$replaces})
                 ON CONFLICT DO NOTHING"
            );
        }

        foreach ([[10759, '28, 12'], [10765, '878, 14']] as [$compound, $halves]) {
            $this->addSql(
                "INSERT INTO movie_genre (movie_id, genre_id)
                 SELECT mg.movie_id, half.id
                 FROM movie_genre mg
                 JOIN genre compound ON compound.id = mg.genre_id AND compound.tmdb_id = {$compound}
                 JOIN genre half ON half.tmdb_id IN ({$halves})
                 ON CONFLICT DO NOTHING"
            );
        }

        // Cascades onto the links that are now redundant.
        $this->addSql('DELETE FROM genre WHERE tmdb_id IN (10759, 10765)');
    }

    public function down(Schema $schema): void
    {
        // Reconstructible because the split is the only way a series can carry these four:
        // TMDB's television vocabulary contains none of them, so a series holding both
        // halves of a pair is one this migration took apart.
        foreach ([
            [10759, 'Action & Adventure', '01a04ee3-8220-7cd1-a843-6a7b181251ed', 28, 12],
            [10765, 'Science-Fiction & Fantastique', '01a04ee3-8220-7cdd-a843-6a7b183ccfe4', 878, 14],
        ] as [$compound, $name, $uuid, $first, $second]) {
            $this->addSql(
                "INSERT INTO genre (id, tmdb_id, name)
                 VALUES ('{$uuid}'::uuid, {$compound}, '{$name}')
                 ON CONFLICT DO NOTHING"
            );

            $this->addSql(
                "INSERT INTO movie_genre (movie_id, genre_id)
                 SELECT m.id, (SELECT id FROM genre WHERE tmdb_id = {$compound})
                 FROM movie m
                 WHERE m.media_type = 'series'
                   AND EXISTS (
                       SELECT 1 FROM movie_genre mg JOIN genre g ON g.id = mg.genre_id
                       WHERE mg.movie_id = m.id AND g.tmdb_id = {$first}
                   )
                   AND EXISTS (
                       SELECT 1 FROM movie_genre mg JOIN genre g ON g.id = mg.genre_id
                       WHERE mg.movie_id = m.id AND g.tmdb_id = {$second}
                   )
                 ON CONFLICT DO NOTHING"
            );

            $this->addSql(
                "DELETE FROM movie_genre mg
                 USING genre g, movie m
                 WHERE mg.genre_id = g.id
                   AND mg.movie_id = m.id
                   AND m.media_type = 'series'
                   AND g.tmdb_id IN ({$first}, {$second})"
            );
        }
    }
}
