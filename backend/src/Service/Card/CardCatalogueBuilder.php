<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\Enum\CardSubject;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Rebuilds one account's card catalogue from its library.
 *
 * Four scoring passes, then one ranking pass that turns scores into tiers, then a sweep, in
 * that order and for reasons that are not interchangeable: the person pass reads the work
 * pass's scores, the ranking pass has to see every subject at once because a percentile band
 * is a fact about the whole set, and the sweep has to run before the ranking so that cards
 * which fell out of the library are not ranked among the ones still in it.
 *
 * **Ownership survives.** Every pass is an upsert and never a truncate: `copies`,
 * `first_owned_at`, `owned_rarity` and `showcase_position` are simply absent from every
 * SET list. The sweep then marks an owned card whose subject left the library as a relic —
 * `in_catalogue = false`, undrawable, still in the album — rather than deleting it. That is
 * the only humane answer when a corrected Letterboxd slug would otherwise take somebody's
 * Légendaire with it. Unowned cards that fell out are simply deleted.
 *
 * **Why this one loads rows into PHP when the rest of the feature refuses to.** Postgres 16
 * has no uuidv7() — that arrives in 18 — and gen_random_uuid() would break the version-7
 * promise HasUuid makes and lose its insert locality. So the scored rows come back, get an
 * id minted per row, and go out again in chunked multi-row inserts. It is deliberate and it
 * is bounded: this runs in a worker, and a few thousand rows of half a dozen scalars is
 * under a megabyte. The rule that matters — never load the catalogue to open a pack —
 * belongs to the draw, and the draw returns exactly one row per card.
 */
final class CardCatalogueBuilder
{
    /**
     * Rows per INSERT. Five hundred × nine bound parameters is 4 500, comfortably inside
     * Postgres' 65 535 limit with room for the passes to grow a column.
     */
    private const INSERT_CHUNK = 500;

    /** How many top cards the calibration report brings back. */
    private const REPORT_SAMPLE = 25;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardCabinetService $cabinets,
    ) {
    }

    public function rebuild(User $user): CardCatalogueReport
    {
        $startedAt = microtime(true);
        $this->cabinets->ensure($user);

        $connection = $this->entityManager->getConnection();
        $userId = (string) $user->getId();
        $runStart = new \DateTimeImmutable();
        $currentYear = (int) $runStart->format('Y');

        $connection->transactional(function (Connection $connection) use ($userId, $runStart, $currentYear): void {
            $this->upsert($connection, $userId, CardSubject::WORK, $this->scoreWorks($connection, $userId, $currentYear), $runStart);
            $this->upsert($connection, $userId, CardSubject::PERSON, $this->scorePeople($connection, $userId), $runStart);
            $this->upsert($connection, $userId, CardSubject::STUDIO, $this->scoreStudios($connection, $userId), $runStart);
            $this->upsert($connection, $userId, CardSubject::FRANCHISE, $this->scoreFranchises($connection, $userId), $runStart);

            $this->sweep($connection, $userId, $runStart);
            $this->rank($connection, $userId);
            $this->refreshCabinet($connection, $userId, $runStart);
        });

        return $this->report($connection, $userId, microtime(true) - $startedAt);
    }

    // ------------------------------------------------------------------ passes

    /**
     * Pass 1 — the works themselves, the only subject with metrics of its own.
     *
     * Two readings of the library are load-bearing here and they deliberately differ:
     *
     *  - `view_count` excludes CSV_RERATING, because a revised rating is not an evening. It
     *    is the house convention (BadgeService, DiscoveryStatsService, OverviewStatsService
     *    all carry it) and here it decides a card's tier: counting a re-rating would make a
     *    card rarer because somebody changed their mind. The HAVING then drops the
     *    pathological work whose only row *is* a re-rating — watched by nobody, rated once.
     *  - the rating does *not* exclude it, and takes the latest rather than the mean. A
     *    revision is the current opinion, which is exactly what this wants; averaging the
     *    old score with the new one would score a film by an opinion its owner has dropped.
     *
     * @return list<array<string, mixed>>
     */
    private function scoreWorks(Connection $connection, string $userId, int $currentYear): array
    {
        $age = CardScoreWeights::age('m.release_year', ':currentYear');

        $audience = sprintf(
            '(%s * %s + %s * %s)',
            CardScoreWeights::number(CardScoreWeights::WORK_AUDIENCE_POPULARITY),
            CardScoreWeights::logScale('m.popularity', CardScoreWeights::CEILING_POPULARITY),
            CardScoreWeights::number(CardScoreWeights::WORK_AUDIENCE_VOTES),
            CardScoreWeights::logScale('m.tmdb_vote_count', CardScoreWeights::CEILING_VOTE_COUNT)
        );

        $personal = sprintf(
            '(%s * COALESCE((wd.rating - 0.5) / 4.5, %s) + %s * LEAST(1, GREATEST(0, (wd.view_count - 1)::numeric / %s)))',
            CardScoreWeights::number(CardScoreWeights::WORK_PERSONAL_RATING),
            CardScoreWeights::number(CardScoreWeights::UNRATED_RATING_SCORE),
            CardScoreWeights::number(CardScoreWeights::WORK_PERSONAL_REWATCH),
            CardScoreWeights::number(CardScoreWeights::REWATCH_SATURATION)
        );

        $prestige = sprintf(
            "CASE WHEN m.media_type = 'series'
                THEN %s * %s + %s * %s
                ELSE %s * %s + %s * (CASE WHEN m.franchise_id IS NOT NULL THEN 1 ELSE 0 END) + %s * %s
            END",
            CardScoreWeights::number(CardScoreWeights::SERIES_PRESTIGE_AGE),
            $age,
            CardScoreWeights::number(CardScoreWeights::SERIES_PRESTIGE_RUNTIME),
            CardScoreWeights::logScale('m.runtime_minutes', CardScoreWeights::CEILING_SERIES_RUNTIME),
            CardScoreWeights::number(CardScoreWeights::FILM_PRESTIGE_REVENUE),
            // A zero revenue means "not recorded" on TMDB rather than "made no money" — the
            // same reading BadgeService already applies to budget. Counting those as zero
            // would put most of a library in the cheapest bracket of its own prestige term.
            CardScoreWeights::logScale('NULLIF(m.revenue, 0)', CardScoreWeights::CEILING_FILM_REVENUE),
            CardScoreWeights::number(CardScoreWeights::FILM_PRESTIGE_FRANCHISE),
            CardScoreWeights::number(CardScoreWeights::FILM_PRESTIGE_AGE),
            $age
        );

        $score = sprintf(
            'ROUND(100 * (%s * %s + %s * %s + %s * %s + %s * (%s)), 3)',
            CardScoreWeights::number(CardScoreWeights::WORK_AUDIENCE),
            $audience,
            CardScoreWeights::number(CardScoreWeights::WORK_ACCLAIM),
            CardScoreWeights::acclaim('m.tmdb_vote_average', 'm.tmdb_vote_count'),
            CardScoreWeights::number(CardScoreWeights::WORK_PERSONAL),
            $personal,
            CardScoreWeights::number(CardScoreWeights::WORK_PRESTIGE),
            $prestige
        );

        return $connection->executeQuery(
            "WITH watched AS (
                SELECT w.movie_id,
                    COUNT(*) FILTER (WHERE w.source <> :deduced) AS view_count,
                    -- The current rating, not the mean of every rating ever given.
                    (ARRAY_AGG(w.rating ORDER BY w.rated_on DESC NULLS LAST, w.watched_date DESC NULLS LAST, w.id DESC)
                        FILTER (WHERE w.rating IS NOT NULL))[1] AS rating
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
                HAVING COUNT(*) FILTER (WHERE w.source <> :deduced) > 0
            )
            SELECT m.id AS subject_id,
                m.title AS label,
                m.poster_path AS image_path,
                m.release_year AS release_year,
                1 AS work_count,
                {$score} AS score
            FROM watched wd
            JOIN movie m ON m.id = wd.movie_id",
            [
                'userId' => $userId,
                'deduced' => WatchSource::CSV_RERATING->value,
                'currentYear' => $currentYear,
            ]
        )->fetchAllAssociative();
    }

    /**
     * Pass 2 — everyone reachable through a credit on a card the first pass just scored.
     *
     * Person carries a tmdbId, a name and a photo path and nothing else — no popularity, no
     * biography — so a person's standing here is purely their footprint in *this* library:
     * how many of its works they touch, how prominently, how good those works are, and how
     * often they were watched.
     *
     * Grouping by (person, work) before aggregating is mandatory, and DiscoveryStatsService
     * spells out why: somebody who directs what they star in carries two credits on one
     * film, and TMDB doubles ensemble actors under two character names. MAX() over the pair
     * takes their best hat; counting credits would weight that film two or three times.
     *
     * The floor — two works, or one headline credit — is the dial that sets the size of the
     * whole catalogue. Without it a 744-work library mints around six thousand people, most
     * of them one-line parts, and the album becomes a phone book.
     *
     * @return list<array<string, mixed>>
     */
    private function scorePeople(Connection $connection, string $userId): array
    {
        $billing = CardScoreWeights::billing('cr.role', 'cr.cast_order');

        $score = sprintf(
            'ROUND(100 * (%s * %s + %s * agg.billing + %s * (agg.quality / 100) + %s * %s), 3)',
            CardScoreWeights::number(CardScoreWeights::PERSON_REACH),
            CardScoreWeights::logScale('agg.work_count', CardScoreWeights::CEILING_PERSON_WORKS),
            CardScoreWeights::number(CardScoreWeights::PERSON_BILLING),
            CardScoreWeights::number(CardScoreWeights::PERSON_QUALITY),
            CardScoreWeights::number(CardScoreWeights::PERSON_VIEWS),
            CardScoreWeights::logScale('agg.views', CardScoreWeights::CEILING_PERSON_VIEWS)
        );

        return $connection->executeQuery(
            "WITH work_card AS (
                SELECT c.movie_id, c.score
                FROM card c
                WHERE c.user_id = :userId AND c.subject = 'work' AND c.in_catalogue
            ),
            viewings AS (
                SELECT w.movie_id, COUNT(*) AS view_count
                FROM watch w
                WHERE w.user_id = :userId AND w.source <> :deduced
                GROUP BY w.movie_id
            ),
            person_work AS (
                SELECT cr.person_id, cr.movie_id,
                    MAX({$billing}) AS billing,
                    BOOL_OR(cr.role IN ('director', 'creator')) AS leads
                FROM credit cr
                JOIN work_card wc ON wc.movie_id = cr.movie_id
                GROUP BY cr.person_id, cr.movie_id
            ),
            agg AS (
                SELECT pw.person_id,
                    COUNT(*) AS work_count,
                    AVG(pw.billing) AS billing,
                    AVG(wc.score) AS quality,
                    COALESCE(SUM(v.view_count), 0) AS views,
                    BOOL_OR(pw.leads OR pw.billing >= :headline) AS headline
                FROM person_work pw
                JOIN work_card wc ON wc.movie_id = pw.movie_id
                LEFT JOIN viewings v ON v.movie_id = pw.movie_id
                GROUP BY pw.person_id
            )
            SELECT p.id AS subject_id,
                p.name AS label,
                p.profile_path AS image_path,
                NULL::int AS release_year,
                agg.work_count AS work_count,
                {$score} AS score
            FROM agg
            JOIN person p ON p.id = agg.person_id
            WHERE agg.work_count >= :minimumWorks OR agg.headline",
            [
                'userId' => $userId,
                'deduced' => WatchSource::CSV_RERATING->value,
                'headline' => CardScoreWeights::HEADLINE_BILLING,
                'minimumWorks' => CardScoreWeights::PERSON_MINIMUM_WORKS,
            ]
        )->fetchAllAssociative();
    }

    /**
     * Pass 3 — production companies.
     *
     * No image, ever: Studio holds a tmdbId and a name, and no logo was imported. The card
     * face draws a typographic plate whenever image_path is null, so this is not a gap to
     * fill but the shared fallback doing its job.
     *
     * @return list<array<string, mixed>>
     */
    private function scoreStudios(Connection $connection, string $userId): array
    {
        $score = sprintf(
            'ROUND(100 * (%s * %s + %s * (agg.quality / 100) + %s * %s), 3)',
            CardScoreWeights::number(CardScoreWeights::STUDIO_REACH),
            CardScoreWeights::logScale('agg.work_count', CardScoreWeights::CEILING_STUDIO_WORKS),
            CardScoreWeights::number(CardScoreWeights::STUDIO_QUALITY),
            CardScoreWeights::number(CardScoreWeights::STUDIO_REVENUE),
            CardScoreWeights::logScale('agg.revenue', CardScoreWeights::CEILING_STUDIO_REVENUE)
        );

        return $connection->executeQuery(
            "WITH work_card AS (
                SELECT c.movie_id, c.score
                FROM card c
                WHERE c.user_id = :userId AND c.subject = 'work' AND c.in_catalogue
            ),
            agg AS (
                SELECT ms.studio_id,
                    COUNT(*) AS work_count,
                    AVG(wc.score) AS quality,
                    COALESCE(SUM(NULLIF(m.revenue, 0)), 0) AS revenue
                FROM work_card wc
                JOIN movie m ON m.id = wc.movie_id
                JOIN movie_studio ms ON ms.movie_id = wc.movie_id
                GROUP BY ms.studio_id
                HAVING COUNT(*) >= :minimumWorks
            )
            SELECT s.id AS subject_id,
                s.name AS label,
                NULL::varchar AS image_path,
                NULL::int AS release_year,
                agg.work_count AS work_count,
                {$score} AS score
            FROM agg
            JOIN studio s ON s.id = agg.studio_id",
            [
                'userId' => $userId,
                'minimumWorks' => CardScoreWeights::STUDIO_MINIMUM_WORKS,
            ]
        )->fetchAllAssociative();
    }

    /**
     * Pass 4 — sagas.
     *
     * The coverage term is the one signal no other source in this app could express:
     * franchise_film holds *every* film of a saga whether it was watched or not, so owning
     * eight of eight Alien films makes the Alien card rarer than owning two of them does.
     * Completing a collection is rewarded by the collection itself.
     *
     * @return list<array<string, mixed>>
     */
    private function scoreFranchises(Connection $connection, string $userId): array
    {
        $coverage = 'LEAST(1, agg.work_count::numeric / GREATEST(COALESCE(total.films, agg.work_count), 1))';

        $score = sprintf(
            'ROUND(100 * (%s * %s + %s * (agg.quality / 100) + %s * %s + %s * %s), 3)',
            CardScoreWeights::number(CardScoreWeights::FRANCHISE_REACH),
            CardScoreWeights::logScale('agg.work_count', CardScoreWeights::CEILING_FRANCHISE_WORKS),
            CardScoreWeights::number(CardScoreWeights::FRANCHISE_QUALITY),
            CardScoreWeights::number(CardScoreWeights::FRANCHISE_COVERAGE),
            $coverage,
            CardScoreWeights::number(CardScoreWeights::FRANCHISE_REVENUE),
            CardScoreWeights::logScale('agg.revenue', CardScoreWeights::CEILING_FRANCHISE_REVENUE)
        );

        return $connection->executeQuery(
            "WITH work_card AS (
                SELECT c.movie_id, c.score
                FROM card c
                WHERE c.user_id = :userId AND c.subject = 'work' AND c.in_catalogue
            ),
            agg AS (
                SELECT m.franchise_id,
                    COUNT(*) AS work_count,
                    AVG(wc.score) AS quality,
                    COALESCE(SUM(NULLIF(m.revenue, 0)), 0) AS revenue
                FROM work_card wc
                JOIN movie m ON m.id = wc.movie_id
                WHERE m.franchise_id IS NOT NULL
                GROUP BY m.franchise_id
            ),
            total AS (
                SELECT ff.franchise_id, COUNT(*) AS films
                FROM franchise_film ff
                GROUP BY ff.franchise_id
            )
            SELECT f.id AS subject_id,
                f.name AS label,
                f.poster_path AS image_path,
                NULL::int AS release_year,
                agg.work_count AS work_count,
                {$score} AS score
            FROM agg
            JOIN franchise f ON f.id = agg.franchise_id
            LEFT JOIN total ON total.franchise_id = agg.franchise_id",
            ['userId' => $userId]
        )->fetchAllAssociative();
    }

    // ------------------------------------------------------------------- write

    /**
     * Upserts one pass's rows, in chunks.
     *
     * The SET list is the whole point: `copies`, `first_owned_at`, `owned_rarity` and
     * `showcase_position` are absent from it, so a rebuild rewrites what the library says
     * and never touches what the player earned. `rarity`, `percentile` and `catalogue_rank`
     * are absent too, because the ranking pass owns them — on insert they get placeholders
     * that survive for the length of this transaction.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function upsert(Connection $connection, string $userId, CardSubject $subject, array $rows, \DateTimeImmutable $scoredAt): void
    {
        if ([] === $rows) {
            return;
        }

        $foreignKey = $subject->foreignKeyColumn();
        $stamp = $scoredAt->format('Y-m-d H:i:s');

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $parameters = [];

            foreach ($chunk as $row) {
                // The subject is interpolated and everything else is bound: it is an enum
                // case chosen by this method's own caller, never a value off a request.
                $placeholders[] = sprintf("(?, ?, '%s', ?, ?, ?, ?, ?, ?, 0, 0, 'common', 0, TRUE, ?)", $subject->value);
                array_push(
                    $parameters,
                    (string) new UuidV7(),
                    $userId,
                    (string) $row['subject_id'],
                    (string) $row['label'],
                    null !== $row['image_path'] ? (string) $row['image_path'] : null,
                    null !== $row['release_year'] ? (int) $row['release_year'] : null,
                    (int) $row['work_count'],
                    (string) $row['score'],
                    $stamp
                );
            }

            $connection->executeStatement(
                sprintf(
                    'INSERT INTO card (
                        id, user_id, subject, %s, label, image_path, release_year, work_count,
                        score, percentile, catalogue_rank, rarity, copies, in_catalogue, scored_at
                    ) VALUES %s
                    ON CONFLICT (user_id, %s) DO UPDATE SET
                        label = EXCLUDED.label,
                        image_path = EXCLUDED.image_path,
                        release_year = EXCLUDED.release_year,
                        work_count = EXCLUDED.work_count,
                        score = EXCLUDED.score,
                        in_catalogue = TRUE,
                        scored_at = EXCLUDED.scored_at',
                    $foreignKey,
                    implode(', ', $placeholders),
                    $foreignKey
                ),
                $parameters
            );
        }
    }

    /**
     * Retires everything this run did not touch.
     *
     * Owned cards become relics rather than disappearing — see the class docblock. Unowned
     * ones are deleted, except any still pinned to the showcase, which cannot happen today
     * (the showcase only accepts owned cards) and is guarded anyway because a constraint
     * that quietly depends on another feature's rule is a constraint that breaks when that
     * rule changes.
     *
     * The comparison is `< :runStart` on a second-precision column, so two full rebuilds
     * inside the same second would leave the earlier one's fallen-out rows in place. They
     * are picked up by the next rebuild; a missed sweep is a stale row, not a wrong one.
     */
    private function sweep(Connection $connection, string $userId, \DateTimeImmutable $runStart): void
    {
        $parameters = ['userId' => $userId, 'runStart' => $runStart->format('Y-m-d H:i:s')];

        $connection->executeStatement(
            'UPDATE card SET in_catalogue = FALSE
            WHERE user_id = :userId AND scored_at < :runStart AND copies > 0 AND in_catalogue',
            $parameters
        );

        $connection->executeStatement(
            'DELETE FROM card
            WHERE user_id = :userId AND scored_at < :runStart AND copies = 0 AND showcase_position IS NULL',
            $parameters
        );
    }

    /**
     * Turns scores into tiers, and then puts the whole catalogue in album order.
     *
     * Two statements, because they answer two different questions and the second needs the
     * first's answer.
     *
     * **The tiers are cut within each subject.** RarityBands explains why at length: the
     * four subjects are scored on scales that cannot be made comparable, and the first
     * calibration run against a real library cut globally and produced twenty-nine
     * Légendaires of which every one was a studio or a saga. Partitioning by subject gives
     * each of them the same shares, so the top shelf holds the best films and the best
     * people and the best studios, and the union still lands on the advertised share of the
     * catalogue. `percentile` is therefore within-subject too, which is also the only
     * reading that makes "top 0,4 %" mean anything on a card's face.
     *
     * **ROW_NUMBER() and not PERCENT_RANK() or NTILE()**, for three reasons that all bite.
     * Ties: studios and low-footprint people produce large groups at identical scores, and
     * PERCENT_RANK() gives a whole group one value, so it lands entirely on one side of a
     * boundary and the target shares break by tens of cards. Granularity: NTILE cannot
     * express 0.6 % of a few thousand rows. Exactness: rank-and-cut gives precisely
     * CEIL(N × share) cards per band, which is what makes the published odds true.
     *
     * md5(id::text) breaks ties deterministically. The id survives an upsert, so a rebuild
     * over an unchanged library produces the identical ordering — which is what lets the
     * album promise that winning a card fills a slot instead of reshuffling the shelf.
     */
    private function rank(Connection $connection, string $userId): void
    {
        $rarity = RarityBands::caseExpression('r.position', 'r.total');

        $connection->executeStatement(
            "WITH ranked AS (
                SELECT c.id,
                    ROW_NUMBER() OVER (PARTITION BY c.subject ORDER BY c.score DESC, md5(c.id::text)) AS position,
                    COUNT(*) OVER (PARTITION BY c.subject) AS total
                FROM card c
                WHERE c.user_id = :userId AND c.in_catalogue
            )
            UPDATE card c
            SET percentile = ROUND(LEAST(1, (r.position - 1)::numeric / GREATEST(r.total - 1, 1)), 6),
                rarity = {$rarity}
            FROM ranked r
            WHERE c.id = r.id",
            ['userId' => $userId]
        );

        // Album order: best shelf first, and by score inside a shelf. Ordering by score
        // alone would open the album on a studio that outranks every film in it, which is
        // true of the arithmetic and wrong for a collection.
        $rarityRank = RarityBands::rankExpression('c.rarity');

        $connection->executeStatement(
            "WITH ordered AS (
                SELECT c.id,
                    ROW_NUMBER() OVER (ORDER BY {$rarityRank} DESC, c.score DESC, md5(c.id::text)) AS position
                FROM card c
                WHERE c.user_id = :userId AND c.in_catalogue
            )
            UPDATE card c
            SET catalogue_rank = o.position
            FROM ordered o
            WHERE c.id = o.id",
            ['userId' => $userId]
        );
    }

    /**
     * Writes the two counts every later request reads instead of computing.
     *
     * `catalogue_work_count` is what the 500-work gate checks, which is why the gate costs
     * no query at request time; `catalogue_built_at` is what the lazy staleness check
     * compares against before queueing another rebuild.
     */
    private function refreshCabinet(Connection $connection, string $userId, \DateTimeImmutable $builtAt): void
    {
        $connection->executeStatement(
            'UPDATE card_cabinet SET
                catalogue_built_at = :builtAt,
                catalogue_card_count = (SELECT COUNT(*) FROM card WHERE user_id = :userId AND in_catalogue),
                catalogue_work_count = (
                    SELECT COUNT(DISTINCT w.movie_id) FROM watch w
                    WHERE w.user_id = :userId AND w.source <> :deduced
                )
            WHERE user_id = :userId',
            [
                'userId' => $userId,
                'builtAt' => $builtAt->format('Y-m-d H:i:s'),
                'deduced' => WatchSource::CSV_RERATING->value,
            ]
        );
    }

    private function report(Connection $connection, string $userId, float $seconds): CardCatalogueReport
    {
        $counts = $connection->executeQuery(
            'SELECT subject, rarity, COUNT(*) AS n FROM card
            WHERE user_id = :userId AND in_catalogue GROUP BY subject, rarity',
            ['userId' => $userId]
        )->fetchAllAssociative();

        $bySubject = [];
        $byRarity = [];
        foreach ($counts as $row) {
            $bySubject[(string) $row['subject']] = ($bySubject[(string) $row['subject']] ?? 0) + (int) $row['n'];
            $byRarity[(string) $row['rarity']] = ($byRarity[(string) $row['rarity']] ?? 0) + (int) $row['n'];
        }

        $legendaries = $connection->executeQuery(
            "SELECT label, subject, score FROM card
            WHERE user_id = :userId AND in_catalogue AND rarity = 'legendary'
            ORDER BY catalogue_rank
            LIMIT :limit",
            ['userId' => $userId, 'limit' => self::REPORT_SAMPLE],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $cabinet = $connection->executeQuery(
            'SELECT catalogue_work_count, catalogue_card_count FROM card_cabinet WHERE user_id = :userId',
            ['userId' => $userId]
        )->fetchAssociative() ?: ['catalogue_work_count' => 0, 'catalogue_card_count' => 0];

        $relics = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM card WHERE user_id = :userId AND NOT in_catalogue',
            ['userId' => $userId]
        )->fetchOne();

        return new CardCatalogueReport(
            (int) $cabinet['catalogue_work_count'],
            (int) $cabinet['catalogue_card_count'],
            $relics,
            $bySubject,
            $byRarity,
            array_map(
                static fn (array $row) => [
                    'label' => (string) $row['label'],
                    'subject' => (string) $row['subject'],
                    'score' => (string) $row['score'],
                ],
                $legendaries
            ),
            $seconds
        );
    }
}
