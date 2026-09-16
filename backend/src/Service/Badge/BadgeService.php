<?php

declare(strict_types=1);

namespace App\Service\Badge;

use App\DTO\Badge\BadgeDto;
use App\Entity\Enum\BadgeCategory;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use App\Service\Stats\BudgetStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every badge a profile has earned, computed from its watch rows and stored nowhere.
 *
 * Deriving rather than recording is the decision the rest of this file follows from. An
 * earned-badge table would have to be written by the importer, the RSS sync and every
 * manual correction, and it would drift the first time one of them was missed — a badge for
 * a film later removed, or none for a library imported before the feature existed. Derived,
 * the shelf is always exactly what the library says it is, and this feature needed no
 * migration at all.
 *
 * The one thing that looks like it should be stored is the date. It is not: the day a level
 * was reached is the watch date of the work that crossed it, which the watch rows already
 * know. It also means the dates are right for the seven hundred works imported long before
 * any of this existed, which a recorded date could never have been.
 *
 * Ten categories, three query shapes, one statement each. The shapes differ only in how a
 * work is attached to a subject — through a link table, through a credit, or through a
 * column of its own — so they share everything else below.
 */
final class BadgeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * @param BadgeCategory|null $only narrows to one category, and skips the other nine
     *                                 queries rather than filtering their results away
     *
     * @return list<BadgeDto>
     */
    public function getBadges(User $user, ?BadgeCategory $only = null): array
    {
        $badges = [];

        foreach (null !== $only ? [$only] : BadgeCategory::cases() as $category) {
            foreach ($this->rowsFor($user, $category) as $row) {
                $badges[] = $this->toDto($category, $row);
            }
        }

        // Highest level first, so the five that reach the profile strip are the five worth
        // showing. Ties break on the count behind the level rather than alphabetically: two
        // level-3 badges are not equal when one of them sits at 19 works and the other at 15.
        usort($badges, static fn (BadgeDto $a, BadgeDto $b) => [$b->level, $b->workCount, $b->earnedOn ?? '']
            <=> [$a->level, $a->workCount, $a->earnedOn ?? '']
            ?: strcmp($a->label, $b->label));

        return $badges;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(User $user, BadgeCategory $category): array
    {
        [$join, $subject, $label, $filter] = $this->shapeOf($category);

        $params = [
            'userId' => (string) $user->getId(),
            'deducedSource' => WatchSource::CSV_RERATING->value,
        ];

        $role = $category->creditRole();
        if (null !== $role) {
            $params['role'] = $role->value;
        }

        $first = BadgeLadder::FIRST_LEVEL;
        $threshold = BadgeLadder::thresholdExpression('work_count');

        return $this->entityManager->getConnection()->executeQuery(
            "WITH watched AS (
                -- One row per work, carrying the day it was first really watched. Revised
                -- ratings are excluded here for the same reason they are everywhere else: a
                -- note moved in 2026 would date a badge to 2026, and the badge would claim a
                -- level was reached on an evening nobody spent in front of anything.
                SELECT m.id AS movie_id,
                    m.backdrop_path AS backdrop_path,
                    m.release_year AS release_year,
                    m.budget AS budget,
                    MIN(w.watched_date) FILTER (WHERE w.source <> :deducedSource) AS watched_on
                FROM watch w
                JOIN movie m ON m.id = w.movie_id
                WHERE w.user_id = :userId
                GROUP BY m.id, m.backdrop_path, m.release_year, m.budget
            ),
            subject AS (
                SELECT {$subject} AS subject_id,
                    {$label} AS label,
                    wd.movie_id AS movie_id,
                    wd.watched_on AS watched_on,
                    wd.backdrop_path AS backdrop_path
                FROM watched wd
                {$join}
                {$filter}
            ),
            ranked AS (
                -- The rank is what makes a date possible at all: the nth work of a subject,
                -- in the order they were watched, is the one that earned whichever level the
                -- ladder puts at n. Undated works sort last so they can never be the one
                -- that crossed a threshold while a dated one was available to.
                SELECT subject_id, label, movie_id, watched_on, backdrop_path,
                    ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY watched_on NULLS LAST, movie_id) AS n,
                    COUNT(*) OVER (PARTITION BY subject_id) AS work_count
                FROM subject
            )
            SELECT subject_id,
                label,
                work_count,
                -- The day the current level was reached: the watch date of the work whose
                -- rank is exactly the rung the tally stands on. The level itself is not
                -- computed here — PHP derives it from work_count off the same ladder.
                MAX(watched_on) FILTER (WHERE n = {$threshold}) AS earned_on,
                -- The badge's face: one still among the works that earned it, drawn by
                -- hashing the subject with each work so the draw is stable. A badge that
                -- changed picture between two visits would not read as the same badge.
                (ARRAY_AGG(backdrop_path ORDER BY md5(subject_id || movie_id::text))
                    FILTER (WHERE backdrop_path IS NOT NULL))[1] AS backdrop_path
            FROM ranked
            WHERE work_count >= {$first}
            GROUP BY subject_id, label, work_count",
            $params
        )->fetchAllAssociative();
    }

    /**
     * How a work hangs off a subject, per category: the join, the subject's identity, its
     * name, and what to leave out.
     *
     * @return array{string, string, string, string} join, subject, label, filter
     */
    private function shapeOf(BadgeCategory $category): array
    {
        return match ($category) {
            BadgeCategory::GENRE => [
                'JOIN movie_genre mg ON mg.movie_id = wd.movie_id JOIN genre g ON g.id = mg.genre_id',
                // The name and not the id: it is what the library listing filters on, so the
                // badge can lead straight back to what it counted.
                'g.name', 'g.name', '',
            ],
            BadgeCategory::COUNTRY => [
                'JOIN movie_country mc ON mc.movie_id = wd.movie_id JOIN country c ON c.id = mc.country_id',
                'c.name', 'c.name', '',
            ],
            BadgeCategory::STUDIO => [
                'JOIN movie_studio ms ON ms.movie_id = wd.movie_id JOIN studio s ON s.id = ms.studio_id',
                's.id::text', 's.name', '',
            ],
            BadgeCategory::DECADE => [
                '', "((wd.release_year / 10) * 10)::text", "((wd.release_year / 10) * 10)::text",
                'WHERE wd.release_year IS NOT NULL',
            ],
            BadgeCategory::BUDGET => [
                '', $this->budgetBand(), $this->budgetBand(),
                // A zero budget means "not recorded" on TMDB rather than "made for nothing",
                // which is the same reading the budget chart takes. Counting those would put
                // three quarters of a library in the cheapest bracket.
                'WHERE wd.budget IS NOT NULL AND wd.budget > 0',
            ],
            // The five credit categories. DISTINCT is load-bearing: TMDB credits the same
            // actor twice on one film often enough, and a work counted twice would hand out
            // a level that was never earned.
            default => [
                'JOIN (SELECT DISTINCT person_id, movie_id FROM credit WHERE role = :role) cr ON cr.movie_id = wd.movie_id
                    JOIN person p ON p.id = cr.person_id',
                'p.id::text', 'p.name', '',
            ],
        };
    }

    /**
     * The budget bracket a work falls in, as its own bounds in dollars — "5000000-30000000".
     *
     * Bounds rather than an index, and read off BudgetStatsService rather than restated: the
     * dashboard's chart and this badge describe the same brackets, and two lists of numbers
     * meant to agree will not stay in agreement. Wording them is the frontend's job, exactly
     * as it already is for the chart.
     */
    private function budgetBand(): string
    {
        $case = 'CASE';
        $previous = 0;
        foreach (BudgetStatsService::BOUNDS as $bound) {
            $case .= sprintf(" WHEN wd.budget < %d THEN '%d-%d'", (int) $bound, $previous, (int) $bound);
            $previous = (int) $bound;
        }

        return $case.sprintf(" ELSE '%d-' END", $previous);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDto(BadgeCategory $category, array $row): BadgeDto
    {
        $workCount = (int) $row['work_count'];
        $level = BadgeLadder::levelFor($workCount);

        return new BadgeDto(
            category: $category,
            subjectId: (string) $row['subject_id'],
            label: (string) $row['label'],
            level: $level,
            workCount: $workCount,
            worksToNextLevel: BadgeLadder::worksForLevel($level + 1) - $workCount,
            earnedOn: null !== $row['earned_on'] ? (string) $row['earned_on'] : null,
            // w300 rather than the w780 a film page shows: a medallion is never drawn much
            // wider than a thumbnail, and a shelf holds dozens of them at once.
            imageUrl: null !== $row['backdrop_path'] ? "{$this->imageBaseUrl}/w300{$row['backdrop_path']}" : null,
        );
    }
}
