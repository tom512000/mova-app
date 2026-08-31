<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rewrites the film ids stranded inside game_session.guesses by Version20260829181500.
 *
 * That migration replaced every integer primary key with a UUIDv7 and walked its FOREIGN_KEYS
 * map to repoint the children — which is every column Postgres knows refers to a film.
 * `game_session.guesses` is not one of them: it is a JSON array holding movie ids as payload,
 * so nothing repointed it and it still holds the old integers. Reading such a row hands them
 * to `findBy(['id' => ...])`, and DBAL raises "Could not convert PHP value 559 to type uuid"
 * before the board ever renders. Every finished infinite run of the pixel game was a 500.
 *
 * ## Why the old numbers can be recovered at all
 *
 * Version20260829181500::down() says the integers are gone, and they are — but the *order*
 * they were in is not. That migration minted each table's UUIDs walking `ORDER BY created_at
 * ASC, id ASC`, and UUIDv7 sorts by the timestamp it was minted with. Films were inserted in
 * creation order with a gapless sequence, so the n-th film by UUID is the film that used to
 * be id n.
 *
 * ## Why that is checked rather than trusted
 *
 * A single film deleted since would shift every rank past it and silently rewrite fifty
 * boards with the wrong titles — worse than the crash, because nothing would look broken.
 * So the mapping is verified before anything is written: in a *won* run the last guess is
 * the winning one, which must be the answer, and `game_session.movie_id` still holds that
 * answer as a real UUID. That is one independent check per won run, and this migration
 * refuses to touch anything unless every one of them agrees.
 *
 * Rows already holding UUIDs are skipped, so replaying this changes nothing the second time.
 */
final class Version20260831183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewrites the pre-UUID integer film ids left inside game_session.guesses.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(0 === $this->legacyRowCount(), 'Aucune partie ne porte d\'identifiants entiers.');

        $checked = $this->connection->fetchOne(
            'SELECT count(*) FROM '.self::WON_RUNS.' w
             LEFT JOIN '.self::RANKED.' r ON r.rank = w.winning_guess
             WHERE r.id IS DISTINCT FROM w.movie_id'
        );

        if ($checked > 0) {
            throw new \RuntimeException(sprintf(
                'Le rang ne redonne pas la bonne réponse sur %d partie(s) gagnée(s) : la table '
                .'movie a changé depuis Version20260829181500 et la correspondance est perdue. '
                .'Restaurer une sauvegarde plutôt que de réécrire des identifiants au jugé.',
                $checked
            ));
        }

        // One statement: each element is mapped through the rank table in place, keeping its
        // position, because the order of the guesses is the order they were played in. An
        // element the ranking cannot resolve is dropped rather than left as a number — a
        // dropped guess reads as a film gone from the library, which the board already knows
        // how to show, while a leftover integer is the crash all over again.
        $this->addSql(
            'UPDATE game_session s
             SET guesses = COALESCE(mapped.guesses, \'[]\'::json)
             FROM (
                 SELECT s2.id,
                        json_agg(r.id::text ORDER BY g.position) FILTER (WHERE r.id IS NOT NULL) AS guesses
                 FROM game_session s2
                 CROSS JOIN LATERAL jsonb_array_elements(s2.guesses::jsonb)
                     WITH ORDINALITY AS g(value, position)
                 LEFT JOIN '.self::RANKED.' r ON r.rank = (g.value #>> \'{}\')::int
                 WHERE jsonb_typeof(g.value) = \'number\'
                 GROUP BY s2.id
             ) AS mapped
             WHERE mapped.id = s.id'
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Réécrire ces identifiants en entiers restaurerait des données que l\'application '
            .'ne sait pas lire. Il n\'y a rien à défaire.'
        );
    }

    /** Films numbered the way Version20260829181500 walked them: oldest minted UUID first. */
    private const RANKED = '(SELECT id, row_number() OVER (ORDER BY id) AS rank FROM movie)';

    /**
     * Won runs played by naming a film, with the guess that ended them. The duel and the
     * timeline are excluded: neither stores film ids in this column.
     */
    private const WON_RUNS = "(SELECT id, movie_id,
            (guesses ->> (jsonb_array_length(guesses::jsonb) - 1))::int AS winning_guess
        FROM game_session
        WHERE status = 'won'
            AND game NOT IN ('duel', 'timeline')
            AND jsonb_array_length(guesses::jsonb) > 0
            AND jsonb_typeof(guesses::jsonb -> 0) = 'number')";

    private function legacyRowCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM game_session
             WHERE jsonb_array_length(guesses::jsonb) > 0
                 AND jsonb_typeof(guesses::jsonb -> 0) = 'number'"
        );
    }
}
