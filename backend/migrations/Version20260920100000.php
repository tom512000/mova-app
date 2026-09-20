<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

/**
 * Le Cabinet: a card catalogue per account, its jetons, its packs, its sets and its showcase.
 *
 * Five tables, and four decisions worth recording here because none of them is obvious from
 * the DDL alone.
 *
 * **Why `card` is materialised when badges are derived.** BadgeService argues at length that
 * deriving beats storing, and it is right about badges: an earned-badge table would have to
 * be written by the importer, the RSS sync and every manual correction, and would drift the
 * first time one of them was missed. That argument does not reach this table. Ownership is
 * not derivable — how many copies of a card were pulled, when, and at what tier are facts
 * about pack openings that nothing in the library reproduces. And rarity here is a
 * percentile band, which is a fact about the whole set rather than about one row: it cannot
 * be computed for a single card, only for all of them at once, which *is* a materialisation.
 * The third reason is speed, and it is the one that would have forced this anyway: drawing a
 * card has to be one indexed statement, and "every person reachable through a credit on a
 * watched work, scored against the works they appear in" is a four-CTE aggregate over every
 * credit row in the library.
 *
 * **Why four nullable foreign keys and not one polymorphic id.** A card stands for a film, a
 * person, a studio or a saga, and the lazy shape is a `subject_id UUID` with no constraint.
 * Four real columns instead, each with its own FK and ON DELETE CASCADE, so a film dropped
 * by a corrected Letterboxd slug takes its card with it rather than leaving a row pointing
 * at nothing. The four unique constraints coexist because Postgres lets nulls repeat inside
 * a unique index — the same property game_session's daily constraint already leans on — and
 * they are what the rebuild's ON CONFLICT clauses target.
 *
 * **Why `rarity` and `owned_rarity` are two columns.** The live one has to float with the
 * library: the pack odds are percentile bands, and a frozen tier would let the Légendaire
 * band drift until it was no longer 0.6 % of anything. The frozen one has to exist anyway,
 * because a Légendaire pulled in March that became Rare in June after a 200-film import
 * would be a retroactive confiscation. So a collection displays COALESCE(owned_rarity,
 * rarity) and a duplicate is paid on `rarity`, which is the only split that is fair in both
 * directions.
 *
 * **Why the cabinet is its own table.** Same reasoning as letterboxd_sync_state: app_user is
 * the identity and security entity, and a feature's running state does not belong on it.
 * Putting a jeton balance next to a password hash would mean every pack opening takes a
 * write lock on the account row.
 *
 * The data step seeds a cabinet for every account that already exists, so no write path has
 * to carry a find-or-create branch. Ids are minted in PHP as UUIDv7 rather than by
 * gen_random_uuid(), for the reason Version20260829181500 gives: v7 is the promise every
 * other row in this schema makes, and one table quietly breaking it is how that promise
 * stops being worth anything.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le Cabinet : catalogue de cartes par compte, jetons, paquets, séries et vitrine.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('card'), 'Le Cabinet est déjà en place.');

        $this->addSql('CREATE TABLE card (id UUID NOT NULL, user_id UUID NOT NULL, subject VARCHAR(20) NOT NULL, movie_id UUID DEFAULT NULL, person_id UUID DEFAULT NULL, studio_id UUID DEFAULT NULL, franchise_id UUID DEFAULT NULL, label VARCHAR(500) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, release_year INT DEFAULT NULL, work_count INT NOT NULL, score NUMERIC(6, 3) NOT NULL, percentile NUMERIC(7, 6) NOT NULL, catalogue_rank INT NOT NULL, rarity VARCHAR(20) NOT NULL, owned_rarity VARCHAR(20) DEFAULT NULL, copies INT NOT NULL, first_owned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, showcase_position SMALLINT DEFAULT NULL, in_catalogue BOOLEAN NOT NULL, scored_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_161498D3A76ED395 ON card (user_id)');
        $this->addSql('CREATE INDEX IDX_161498D38F93B6FC ON card (movie_id)');
        $this->addSql('CREATE INDEX IDX_161498D3217BBB47 ON card (person_id)');
        $this->addSql('CREATE INDEX IDX_161498D3446F285F ON card (studio_id)');
        $this->addSql('CREATE INDEX IDX_161498D3523CAB89 ON card (franchise_id)');
        // The draw filters on (user, rarity); the album pages on (user, catalogue_rank); the
        // facet counts group on (user, subject).
        $this->addSql('CREATE INDEX idx_card_user_rarity ON card (user_id, rarity)');
        $this->addSql('CREATE INDEX idx_card_user_rank ON card (user_id, catalogue_rank)');
        $this->addSql('CREATE INDEX idx_card_user_subject ON card (user_id, subject)');
        // Four partial identities in one table — see the class comment on repeated nulls.
        $this->addSql('CREATE UNIQUE INDEX uniq_card_user_movie ON card (user_id, movie_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_user_person ON card (user_id, person_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_user_studio ON card (user_id, studio_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_user_franchise ON card (user_id, franchise_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_user_showcase ON card (user_id, showcase_position)');

        $this->addSql('CREATE TABLE card_cabinet (id UUID NOT NULL, user_id UUID NOT NULL, balance INT NOT NULL, lifetime_earned INT NOT NULL, lifetime_spent INT NOT NULL, free_packs_opened INT NOT NULL, paid_packs_opened INT NOT NULL, pity_super_rare INT NOT NULL, pity_legendary INT NOT NULL, last_daily_grant_on DATE DEFAULT NULL, streak_days INT NOT NULL, free_dup_jetons_on DATE DEFAULT NULL, free_dup_jetons_today INT NOT NULL, catalogue_built_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, catalogue_work_count INT NOT NULL, catalogue_card_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_cabinet_user ON card_cabinet (user_id)');

        $this->addSql('CREATE TABLE card_pack_opening (id UUID NOT NULL, user_id UUID NOT NULL, kind VARCHAR(20) NOT NULL, cost INT NOT NULL, jetons_earned INT NOT NULL, cards JSON NOT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2541EABBA76ED395 ON card_pack_opening (user_id)');
        $this->addSql('CREATE INDEX idx_card_pack_opening_user_opened ON card_pack_opening (user_id, opened_at)');

        // The unique constraints on the next two tables are not bookkeeping — they are the
        // idempotency. A second claim and a second payout are refused by the database rather
        // than by a read that a concurrent request could slip past.
        $this->addSql('CREATE TABLE card_set_reward (id UUID NOT NULL, user_id UUID NOT NULL, family VARCHAR(20) NOT NULL, set_key VARCHAR(200) NOT NULL, jetons INT NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_86E6DACCA76ED395 ON card_set_reward (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_set_reward_user_set ON card_set_reward (user_id, family, set_key)');

        $this->addSql('CREATE TABLE card_game_reward (id UUID NOT NULL, user_id UUID NOT NULL, game_session_id UUID NOT NULL, jetons INT NOT NULL, awarded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D4308001A76ED395 ON card_game_reward (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_game_reward_session ON card_game_reward (game_session_id)');

        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D38F93B6FC FOREIGN KEY (movie_id) REFERENCES movie (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3446F285F FOREIGN KEY (studio_id) REFERENCES studio (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3523CAB89 FOREIGN KEY (franchise_id) REFERENCES franchise (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_cabinet ADD CONSTRAINT FK_1BF78FC6A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_pack_opening ADD CONSTRAINT FK_2541EABBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_set_reward ADD CONSTRAINT FK_86E6DACCA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_game_reward ADD CONSTRAINT FK_D4308001A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_game_reward ADD CONSTRAINT FK_D43080018FE32B32 FOREIGN KEY (game_session_id) REFERENCES game_session (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    /**
     * Seeds a cabinet for every account that already exists.
     *
     * Runs after the queued DDL rather than inside it, which is what postUp is for: the
     * statements above have to have executed before a row can be inserted into a table they
     * create. A find-or-create in every write path would be the alternative, and
     * ProfileController::shareLink() is the standing demonstration of how tedious that gets.
     */
    public function postUp(Schema $schema): void
    {
        $userIds = $this->connection->fetchFirstColumn(
            'SELECT u.id FROM app_user u LEFT JOIN card_cabinet c ON c.user_id = u.id WHERE c.id IS NULL'
        );

        foreach ($userIds as $userId) {
            $this->connection->insert('card_cabinet', [
                'id' => (string) new UuidV7(),
                'user_id' => (string) $userId,
                'balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_spent' => 0,
                'free_packs_opened' => 0,
                'paid_packs_opened' => 0,
                'pity_super_rare' => 0,
                'pity_legendary' => 0,
                'streak_days' => 0,
                'free_dup_jetons_today' => 0,
                'catalogue_work_count' => 0,
                'catalogue_card_count' => 0,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Child tables first: card_game_reward references game_session as well as app_user,
        // and dropping in creation order would leave the constraints looking for their parent.
        $this->addSql('DROP TABLE IF EXISTS card_game_reward');
        $this->addSql('DROP TABLE IF EXISTS card_set_reward');
        $this->addSql('DROP TABLE IF EXISTS card_pack_opening');
        $this->addSql('DROP TABLE IF EXISTS card_cabinet');
        $this->addSql('DROP TABLE IF EXISTS card');
    }
}
