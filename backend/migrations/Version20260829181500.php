<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Symfony\Component\Uid\UuidV7;

/**
 * Turns every integer primary key in the application schema into a UUIDv7, keeping the
 * rows exactly where they are — no truncate, no re-import.
 *
 * How it works, and why it is shaped this way:
 *
 *   PostgreSQL cannot rewrite a column's type from a lookup table: `ALTER COLUMN ... TYPE
 *   uuid USING (...)` forbids subqueries in the transform expression. So each table gets a
 *   shadow `id_uuid` column, PHP fills it, every foreign key column gets its own shadow
 *   resolved through the parent's, and only then do the integers go.
 *
 *   The new ids are minted by PHP rather than by the database because version 7 carries a
 *   millisecond timestamp in its first 48 bits, and the honest timestamp for an existing
 *   row is the one already stored in its created_at. Rows keep sorting by age exactly as
 *   they did under the old auto-increment. Tables with nothing to date them by — the
 *   reference lists, credits, the access grants — are minted in their old id order, which
 *   preserves the sequence without inventing a history.
 *
 *   Indexes and foreign keys are not enumerated by hand. They are read out of the catalog
 *   first and replayed verbatim afterwards: the shadow columns are renamed back to the
 *   original names, so every captured definition is still literally correct, and nothing
 *   drifts because someone forgot a line.
 *
 * All the work runs through $this->connection instead of addSql(), because the data steps
 * sit between the DDL steps and have to keep that order. Doctrine will note that this
 * migration "did not result in any SQL statements"; it did, just not through the queue it
 * watches. PostgreSQL makes DDL transactional, so the whole conversion commits or none of
 * it does.
 */
final class Version20260829181500 extends AbstractMigration
{
    /** Ids are rewritten this many rows at a time, in one statement per batch. */
    private const CHUNK = 5000;

    /**
     * Every table keyed on a single integer id, mapped to the column that dates its rows —
     * null where nothing does.
     *
     * messenger_messages and doctrine_migration_versions are deliberately absent: they
     * belong to Symfony and Doctrine, not to this application, and both expect their own
     * key types.
     */
    private const TABLES = [
        'app_user' => 'created_at',
        'movie' => 'created_at',
        'watch' => 'created_at',
        'watchlist_entry' => 'created_at',
        'game_session' => 'created_at',
        'import_batch' => 'created_at',
        'import_row_error' => 'created_at',
        'profile_share_link' => 'created_at',
        'letterboxd_profile' => 'imported_at',
        'favourite_film' => null,
        'letterboxd_sync_state' => null,
        'profile_access' => null,
        'credit' => null,
        'person' => null,
        'genre' => null,
        'country' => null,
        'studio' => null,
        'tag' => null,
    ];

    /** Child table => [referencing column => referenced table]. */
    private const FOREIGN_KEYS = [
        'credit' => ['movie_id' => 'movie', 'person_id' => 'person'],
        'game_session' => ['movie_id' => 'movie', 'user_id' => 'app_user'],
        'import_batch' => ['user_id' => 'app_user'],
        'import_row_error' => ['import_batch_id' => 'import_batch'],
        'letterboxd_profile' => ['user_id' => 'app_user'],
        // Ordered after its parent for readability only — the shadow columns for every table
        // are minted before any foreign key is resolved, so the map order does not matter.
        'favourite_film' => ['profile_id' => 'letterboxd_profile', 'movie_id' => 'movie'],
        'letterboxd_sync_state' => ['user_id' => 'app_user'],
        'movie_country' => ['movie_id' => 'movie', 'country_id' => 'country'],
        'movie_genre' => ['movie_id' => 'movie', 'genre_id' => 'genre'],
        'movie_studio' => ['movie_id' => 'movie', 'studio_id' => 'studio'],
        'profile_access' => ['owner_id' => 'app_user', 'viewer_id' => 'app_user'],
        'profile_share_link' => ['owner_id' => 'app_user'],
        'watch' => ['movie_id' => 'movie', 'user_id' => 'app_user'],
        'watch_tag' => ['watch_id' => 'watch', 'tag_id' => 'tag'],
        'watchlist_entry' => ['movie_id' => 'movie', 'user_id' => 'app_user'],
    ];

    /** The join tables, whose key is the pair of columns rather than an id of their own. */
    private const COMPOSITE_KEYS = [
        'movie_genre' => ['movie_id', 'genre_id'],
        'movie_country' => ['movie_id', 'country_id'],
        'movie_studio' => ['movie_id', 'studio_id'],
        'watch_tag' => ['watch_id', 'tag_id'],
    ];

    public function getDescription(): string
    {
        return 'Replace every integer primary key with a UUIDv7, preserving all rows.';
    }

    public function up(Schema $schema): void
    {
        $indexes = $this->captureIndexes();
        $foreignKeys = $this->captureForeignKeys();

        $this->mintPrimaryKeys();
        $this->resolveForeignKeys();
        $this->dropForeignKeys($foreignKeys);
        $this->swapColumns();
        $this->restoreKeys($indexes, $foreignKeys);
    }

    public function down(Schema $schema): void
    {
        // The integers are gone, and with them the only thing that could map a UUID back to
        // whatever number a row used to carry. Inventing fresh sequence values would produce
        // a schema of the right shape holding different identities — worse than refusing.
        throw new IrreversibleMigration(
            'Les identifiants entiers d\'origine ne sont plus stockés nulle part : '
            .'restaurer une sauvegarde antérieure à cette migration.'
        );
    }

    /**
     * @return list<array{indexname: string, indexdef: string}>
     */
    private function captureIndexes(): array
    {
        // Primary keys are excluded: they come back with their constraint, not as an index
        // definition. Everything else — the plain lookups and the unique constraints
        // Doctrine expresses as unique indexes — is replayed exactly as written here.
        return $this->connection->fetchAllAssociative(
            "SELECT indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = 'public'
                AND tablename = ANY(:tables)
                AND indexname NOT LIKE '%\_pkey'
            ORDER BY indexname",
            ['tables' => $this->pgArray($this->allTables())]
        );
    }

    /**
     * @return list<array{tbl: string, conname: string, def: string}>
     */
    private function captureForeignKeys(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT conrelid::regclass::text AS tbl, conname, pg_get_constraintdef(oid) AS def
            FROM pg_constraint
            WHERE contype = 'f'
                AND connamespace = 'public'::regnamespace
                AND conrelid::regclass::text = ANY(:tables)
            ORDER BY conname",
            ['tables' => $this->pgArray($this->allTables())]
        );
    }

    private function mintPrimaryKeys(): void
    {
        foreach (self::TABLES as $table => $timeColumn) {
            $this->connection->executeStatement("ALTER TABLE {$table} ADD COLUMN id_uuid UUID");
            $this->mint($table, $timeColumn);
            $this->connection->executeStatement("ALTER TABLE {$table} ALTER COLUMN id_uuid SET NOT NULL");
        }
    }

    private function mint(string $table, ?string $timeColumn): void
    {
        $rows = null !== $timeColumn
            ? $this->connection->fetchAllAssociative(
                "SELECT id, {$timeColumn} AS minted_at FROM {$table} ORDER BY {$timeColumn} ASC, id ASC"
            )
            : $this->connection->fetchAllAssociative(
                "SELECT id, NULL AS minted_at FROM {$table} ORDER BY id ASC"
            );

        if ([] === $rows) {
            return;
        }

        $seen = [];

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $ids = [];
            $uuids = [];

            foreach ($chunk as $row) {
                // Stored as `timestamp without time zone`, so the zone has to be stated
                // rather than inherited from whatever the process happens to be set to.
                $mintedAt = null !== $row['minted_at']
                    ? new \DateTimeImmutable((string) $row['minted_at'], new \DateTimeZone('UTC'))
                    : null;

                $uuid = UuidV7::generate($mintedAt);
                $seen[$uuid] = true;

                $ids[] = (int) $row['id'];
                $uuids[] = $uuid;
            }

            // One statement per batch: unnest turns the two arrays into a mapping table the
            // UPDATE can join against, which beats a round trip per row on the 16 000-row
            // credit table by a wide margin.
            $this->connection->executeStatement(
                "UPDATE {$table} t
                SET id_uuid = v.new_id
                FROM (
                    SELECT unnest(CAST(:ids AS INT[])) AS old_id,
                           unnest(CAST(:uuids AS UUID[])) AS new_id
                ) v
                WHERE t.id = v.old_id",
                ['ids' => $this->pgArray($ids), 'uuids' => $this->pgArray($uuids)]
            );
        }

        // A collision would silently merge two rows at the next step, when the shadow
        // column becomes the primary key. 74 random bits make it vanishingly unlikely;
        // checking costs nothing next to finding out afterwards.
        if (\count($seen) !== \count($rows)) {
            throw new \RuntimeException(sprintf('Collision d\'UUID en générant les identifiants de "%s".', $table));
        }
    }

    private function resolveForeignKeys(): void
    {
        foreach (self::FOREIGN_KEYS as $child => $columns) {
            foreach ($columns as $column => $parent) {
                $this->connection->executeStatement("ALTER TABLE {$child} ADD COLUMN {$column}_uuid UUID");
                $this->connection->executeStatement(
                    "UPDATE {$child} c SET {$column}_uuid = p.id_uuid FROM {$parent} p WHERE p.id = c.{$column}"
                );
                // Every foreign key in this schema is NOT NULL, so a row left without a
                // match here means the old integer pointed at nothing — worth failing on.
                $this->connection->executeStatement("ALTER TABLE {$child} ALTER COLUMN {$column}_uuid SET NOT NULL");
            }
        }
    }

    /**
     * @param list<array{tbl: string, conname: string, def: string}> $foreignKeys
     */
    private function dropForeignKeys(array $foreignKeys): void
    {
        // Before the parent id columns, never after: a child still pointing at one would
        // block the drop.
        foreach ($foreignKeys as $fk) {
            $this->connection->executeStatement("ALTER TABLE {$fk['tbl']} DROP CONSTRAINT {$fk['conname']}");
        }
    }

    private function swapColumns(): void
    {
        // DROP COLUMN takes the primary key and every index mentioning that column with it,
        // which is exactly the cleanup wanted here.
        foreach (self::FOREIGN_KEYS as $child => $columns) {
            foreach ($columns as $column => $parent) {
                $this->connection->executeStatement("ALTER TABLE {$child} DROP COLUMN {$column}");
                $this->connection->executeStatement("ALTER TABLE {$child} RENAME COLUMN {$column}_uuid TO {$column}");
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->connection->executeStatement("ALTER TABLE {$table} DROP COLUMN id");
            $this->connection->executeStatement("ALTER TABLE {$table} RENAME COLUMN id_uuid TO id");
        }
    }

    /**
     * @param list<array{indexname: string, indexdef: string}>   $indexes
     * @param list<array{tbl: string, conname: string, def: string}> $foreignKeys
     */
    private function restoreKeys(array $indexes, array $foreignKeys): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $this->connection->executeStatement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_pkey PRIMARY KEY (id)");
        }

        foreach (self::COMPOSITE_KEYS as $table => $columns) {
            $pair = implode(', ', $columns);
            $this->connection->executeStatement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_pkey PRIMARY KEY ({$pair})");
        }

        // Only the ones that actually fell. An index on columns the conversion never
        // touched — idx_movie_enrichment_status, uniq_user_email — survived intact, and
        // recreating it would fail on a name that already exists.
        $survivors = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public'"
        );

        foreach ($indexes as $index) {
            if (!\in_array($index['indexname'], $survivors, true)) {
                $this->connection->executeStatement($index['indexdef']);
            }
        }

        foreach ($foreignKeys as $fk) {
            $this->connection->executeStatement(
                "ALTER TABLE {$fk['tbl']} ADD CONSTRAINT {$fk['conname']} {$fk['def']}"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function allTables(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::TABLES),
            array_keys(self::FOREIGN_KEYS),
        )));
    }

    /**
     * Renders a PHP list as a PostgreSQL array literal. Safe unquoted for the three kinds
     * of value passed here — integers, UUIDs and this schema's table names — none of which
     * can contain a comma, a brace or a backslash.
     *
     * @param list<int|string> $values
     */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
