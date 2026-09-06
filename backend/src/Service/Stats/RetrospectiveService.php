<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\Retrospective\GenreShiftDto;
use App\DTO\Stats\Retrospective\PersonOfTheYearDto;
use App\DTO\Stats\Retrospective\RetrospectiveDto;
use App\DTO\Stats\Retrospective\RetrospectiveMonthDto;
use App\DTO\Stats\Retrospective\RetrospectiveStreakDto;
use App\DTO\Stats\Retrospective\RetrospectiveWorkDto;
use App\DTO\Stats\Retrospective\YearComparisonDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The end-of-year ritual, computed locally.
 *
 * The shapes it needs mostly existed already — the rhythm card knows how to find a run of
 * consecutive days, the genre and people rankings know how to count — but not one of those
 * services takes a year. They all report on the library entire, which is the opposite of
 * what a retrospective is for, so what is reused here is the reasoning rather than the
 * queries. Two conventions do carry over verbatim, and they matter: a viewing needs a date,
 * and a rating revised months later is not an evening in front of a film.
 *
 * Every section is optional. A year holding four viewings has no month that stands out and
 * no genre that took over, and the page has to read as a quiet year rather than a broken one.
 */
final class RetrospectiveService
{
    /** Films named in the top list. Ten is the ritual's own number. */
    private const TOP_RATED_SHOWN = 10;

    /**
     * Viewings a genre needs in the year before it can be called the genre of the year.
     *
     * Without a floor the block is won by accidents: one film in a genre that had none last
     * year is an infinite rise and means nothing. Five is low enough to let a real turn show
     * on a modest year and high enough to keep a single evening from deciding it.
     */
    private const GENRE_MINIMUM_WATCHES = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * Years this profile has anything to show for, most recent first.
     *
     * Drives the selector, so it is deliberately the list of years with viewings rather than
     * a range: a gap year would otherwise be offered as an empty page.
     *
     * @return list<int>
     */
    public function getAvailableYears(User $user): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT DISTINCT EXTRACT(YEAR FROM watched_date)::int AS year
            FROM watch
            WHERE user_id = :userId AND watched_date IS NOT NULL AND source <> :deduced
            ORDER BY year DESC',
            $this->baseParams($user)
        )->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    public function getRetrospective(User $user, int $year): RetrospectiveDto
    {
        $totals = $this->totals($user, $year);
        $previousYear = $this->previousYear($user, $year);

        return new RetrospectiveDto(
            year: $year,
            watchCount: $totals['watch_count'],
            workCount: $totals['work_count'],
            activeDays: $totals['active_days'],
            totalRuntimeMinutes: $totals['runtime_minutes'],
            worksWithoutRuntime: $totals['without_runtime'],
            averageRating: $totals['average_rating'],
            busiestMonth: $this->busiestMonth($user, $year),
            longestStreak: $this->longestStreak($user, $year),
            // Both years' viewing counts are handed in rather than derived from the genre
            // rows, which is the whole correctness of the block — see genreOfTheYear().
            genre: $this->genreOfTheYear($user, $year, $totals['watch_count'], $previousYear?->watchCount ?? 0),
            people: $this->peopleOfTheYear($user, $year),
            oldestDiscovery: $this->oldestDiscovery($user, $year),
            previousYear: $previousYear,
            topRated: $this->topRated($user, $year),
        );
    }

    /**
     * @return array{watch_count: int, work_count: int, active_days: int, runtime_minutes: int, without_runtime: int, average_rating: float|null}
     */
    private function totals(User $user, int $year): array
    {
        $row = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                COUNT(*) AS watch_count,
                COUNT(DISTINCT w.movie_id) AS work_count,
                COUNT(DISTINCT w.watched_date) AS active_days,
                COALESCE(SUM(m.runtime_minutes), 0) AS runtime_minutes,
                -- Counted apart rather than treated as zero, so the hours can be presented
                -- as a floor. A series with no runtime would otherwise quietly shorten the year.
                COUNT(DISTINCT m.id) FILTER (WHERE m.runtime_minutes IS NULL) AS without_runtime,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            '.$this->yearCondition(),
            $this->yearParams($user, $year)
        )->fetchAssociative();

        return [
            'watch_count' => (int) ($row['watch_count'] ?? 0),
            'work_count' => (int) ($row['work_count'] ?? 0),
            'active_days' => (int) ($row['active_days'] ?? 0),
            'runtime_minutes' => (int) ($row['runtime_minutes'] ?? 0),
            'without_runtime' => (int) ($row['without_runtime'] ?? 0),
            'average_rating' => isset($row['average_rating']) && null !== $row['average_rating']
                ? round((float) $row['average_rating'], 2)
                : null,
        ];
    }

    private function busiestMonth(User $user, int $year): ?RetrospectiveMonthDto
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT EXTRACT(MONTH FROM w.watched_date)::int AS month, COUNT(*) AS watch_count
            FROM watch w
            '.$this->yearCondition().'
            GROUP BY month
            ORDER BY watch_count DESC, month ASC',
            $this->yearParams($user, $year)
        )->fetchAllAssociative();

        if ([] === $rows) {
            return null;
        }

        // Averaged over twelve months and not over the months that had something in them:
        // an empty January is part of what makes August stand out, and dividing by the
        // active months only would flatten exactly the contrast this block is about.
        $total = array_sum(array_map(static fn (array $row) => (int) $row['watch_count'], $rows));

        return new RetrospectiveMonthDto(
            month: (int) $rows[0]['month'],
            watchCount: (int) $rows[0]['watch_count'],
            averageMonthCount: round($total / 12, 1),
        );
    }

    /**
     * The longest run of consecutive days, and when it happened.
     *
     * Walked in PHP over the year's distinct days, the same way the rhythm card does it. A
     * window function could do it in SQL, but the year holds at most 366 rows and the
     * readable version is the one that will still be understood the next time it is opened.
     */
    private function longestStreak(User $user, int $year): ?RetrospectiveStreakDto
    {
        $days = $this->entityManager->getConnection()->executeQuery(
            'SELECT w.watched_date AS day, COUNT(*) AS watch_count
            FROM watch w
            '.$this->yearCondition().'
            GROUP BY w.watched_date
            ORDER BY w.watched_date ASC',
            $this->yearParams($user, $year)
        )->fetchAllAssociative();

        if ([] === $days) {
            return null;
        }

        $best = null;
        $startIndex = 0;
        $runWatches = 0;
        $previous = null;

        foreach ($days as $index => $day) {
            $date = new \DateTimeImmutable((string) $day['day']);
            $continues = null !== $previous && 1 === (int) $previous->diff($date)->days;

            if (!$continues) {
                $startIndex = $index;
                $runWatches = 0;
            }

            $runWatches += (int) $day['watch_count'];
            $length = $index - $startIndex + 1;

            if (null === $best || $length > $best['days']) {
                $best = [
                    'days' => $length,
                    'start' => (string) $days[$startIndex]['day'],
                    'end' => (string) $day['day'],
                    'watches' => $runWatches,
                ];
            }

            $previous = $date;
        }

        return new RetrospectiveStreakDto(
            days: $best['days'],
            startDate: $best['start'],
            endDate: $best['end'],
            watchCount: $best['watches'],
        );
    }

    /**
     * The genre whose share of the year rose most, or simply the biggest one on a first year.
     *
     * Shares rather than counts — see GenreShiftDto for why that is the whole block.
     *
     * The share is against the year's *viewings*, which is why both counts are parameters
     * rather than sums of the rows below. Deriving the denominator from those rows was the
     * first version and it was wrong: a film carries several genres, so adding the per-genre
     * counts counts each viewing once per label it wears. Comedy came out at 32% of a
     * thousand-and-twelve labels instead of 78% of four hundred and nineteen evenings — a
     * true statement about nothing anybody wanted to know. Shares now sum past 100%, which
     * is the same convention the country ring and the studio ranking already use, and for
     * the same reason: one film really does belong to each of its genres.
     */
    private function genreOfTheYear(User $user, int $year, int $yearTotal, int $previousTotal): ?GenreShiftDto
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                g.name AS name,
                COUNT(*) FILTER (WHERE EXTRACT(YEAR FROM w.watched_date) = :year) AS watch_count,
                COUNT(*) FILTER (WHERE EXTRACT(YEAR FROM w.watched_date) = :previousYear) AS previous_count
            FROM watch w
            JOIN movie_genre mg ON mg.movie_id = w.movie_id
            JOIN genre g ON g.id = mg.genre_id
            WHERE w.user_id = :userId
                AND w.watched_date IS NOT NULL
                AND w.source <> :deduced
                AND EXTRACT(YEAR FROM w.watched_date) IN (:year, :previousYear)
            GROUP BY g.name',
            $this->yearParams($user, $year) + ['previousYear' => $year - 1]
        )->fetchAllAssociative();

        $eligible = array_values(array_filter(
            $rows,
            static fn (array $r) => (int) $r['watch_count'] >= self::GENRE_MINIMUM_WATCHES
        ));

        if (0 === $yearTotal || [] === $eligible) {
            return null;
        }

        $share = static fn (int $count, int $total) => 0 === $total ? 0.0 : round(100 * $count / $total, 1);

        usort($eligible, static function (array $a, array $b) use ($share, $yearTotal, $previousTotal) {
            // No year before means nothing to have risen from, so the ranking falls back to
            // plain size — which is the honest answer to "the genre of the year" when the
            // year is the first one.
            $rise = static fn (array $r) => 0 === $previousTotal
                ? $share((int) $r['watch_count'], $yearTotal)
                : $share((int) $r['watch_count'], $yearTotal) - $share((int) $r['previous_count'], $previousTotal);

            return [$rise($b), (int) $b['watch_count']] <=> [$rise($a), (int) $a['watch_count']];
        });

        $winner = $eligible[0];

        return new GenreShiftDto(
            genreName: (string) $winner['name'],
            watchCount: (int) $winner['watch_count'],
            share: $share((int) $winner['watch_count'], $yearTotal),
            previousShare: 0 === $previousTotal ? null : $share((int) $winner['previous_count'], $previousTotal),
        );
    }

    /**
     * The most-watched director and the most-watched performer of the year.
     *
     * Two jobs and not five: those are the two anybody picks a film for. See
     * PersonOfTheYearDto on why production credits are left out.
     *
     * @return list<PersonOfTheYearDto>
     */
    private function peopleOfTheYear(User $user, int $year): array
    {
        $people = [];

        foreach ([CreditRole::DIRECTOR, CreditRole::ACTOR] as $role) {
            $row = $this->entityManager->getConnection()->executeQuery(
                'SELECT p.id AS person_id, p.name AS name, p.profile_path AS profile_path,
                    COUNT(DISTINCT w.movie_id) AS work_count
                FROM watch w
                JOIN credit c ON c.movie_id = w.movie_id AND c.role = :role
                JOIN person p ON p.id = c.person_id
                '.$this->yearCondition().'
                GROUP BY p.id, p.name, p.profile_path
                ORDER BY work_count DESC, p.name ASC
                LIMIT 1',
                $this->yearParams($user, $year) + ['role' => $role->value]
            )->fetchAssociative();

            if (false === $row) {
                continue;
            }

            $people[] = new PersonOfTheYearDto(
                personId: (string) $row['person_id'],
                name: (string) $row['name'],
                profileUrl: null !== $row['profile_path']
                    ? "{$this->imageBaseUrl}/w185{$row['profile_path']}"
                    : null,
                role: $role,
                workCount: (int) $row['work_count'],
            );
        }

        return $people;
    }

    /**
     * The oldest film watched for the first time this year.
     *
     * "Discovered", so a rewatch of something already seen in an earlier year does not count
     * — otherwise the block would keep handing back the same old favourite every December.
     */
    private function oldestDiscovery(User $user, int $year): ?RetrospectiveWorkDto
    {
        $row = $this->entityManager->getConnection()->executeQuery(
            'SELECT m.id AS movie_id, m.title AS title, m.release_year AS release_year,
                m.poster_path AS poster_path, m.media_type AS media_type,
                AVG(w.rating) AS rating, MIN(w.watched_date) AS watched_date
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            '.$this->yearCondition().'
                AND m.release_year IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM watch earlier
                    WHERE earlier.movie_id = w.movie_id
                        AND earlier.user_id = :userId
                        AND earlier.watched_date IS NOT NULL
                        AND earlier.source <> :deduced
                        AND EXTRACT(YEAR FROM earlier.watched_date) < :year
                )
            GROUP BY m.id, m.title, m.release_year, m.poster_path, m.media_type
            ORDER BY m.release_year ASC, m.title ASC
            LIMIT 1',
            $this->yearParams($user, $year)
        )->fetchAssociative();

        return false === $row ? null : $this->toWork($row);
    }

    private function previousYear(User $user, int $year): ?YearComparisonDto
    {
        $row = $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) AS watch_count, AVG(rating) AS average_rating
            FROM watch
            WHERE user_id = :userId
                AND watched_date IS NOT NULL
                AND source <> :deduced
                AND EXTRACT(YEAR FROM watched_date) = :year',
            $this->baseParams($user) + ['year' => $year - 1]
        )->fetchAssociative();

        $count = (int) ($row['watch_count'] ?? 0);

        // Nothing the year before means nothing to compare to, and "+419 viewings" against a
        // year that does not exist would read as growth rather than as a beginning.
        return 0 === $count ? null : new YearComparisonDto(
            year: $year - 1,
            watchCount: $count,
            averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
        );
    }

    /**
     * @return list<RetrospectiveWorkDto>
     */
    private function topRated(User $user, int $year): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT m.id AS movie_id, m.title AS title, m.release_year AS release_year,
                m.poster_path AS poster_path, m.media_type AS media_type,
                MAX(w.rating) AS rating, MAX(w.watched_date) AS watched_date
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            '.$this->yearCondition().'
                AND w.rating IS NOT NULL
            GROUP BY m.id, m.title, m.release_year, m.poster_path, m.media_type
            -- The best note given to it during the year, not the average across rewatches:
            -- this list answers "what did you love", and a film adored in March is not
            -- demoted by a lukewarm second viewing in November.
            ORDER BY rating DESC, watched_date DESC, m.title ASC
            LIMIT :limit',
            $this->yearParams($user, $year) + ['limit' => self::TOP_RATED_SHOWN],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        return array_map(fn (array $row) => $this->toWork($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toWork(array $row): RetrospectiveWorkDto
    {
        return new RetrospectiveWorkDto(
            movieId: (string) $row['movie_id'],
            title: (string) $row['title'],
            releaseYear: null !== $row['release_year'] ? (int) $row['release_year'] : null,
            posterUrl: null !== $row['poster_path'] ? "{$this->imageBaseUrl}/w342{$row['poster_path']}" : null,
            mediaType: MediaType::from((string) $row['media_type']),
            rating: null !== $row['rating'] ? (float) $row['rating'] : null,
            watchedDate: null !== $row['watched_date'] ? (string) $row['watched_date'] : null,
        );
    }

    /**
     * The one definition of "a viewing in this year", written once.
     *
     * A date is required and a revised rating is excluded — the same two rules the activity
     * calendar follows, and for the same reason: moving a note months later is a real rating
     * but it is not an evening spent watching something, and a retrospective built on those
     * would credit the year with films nobody sat through in it.
     */
    private function yearCondition(): string
    {
        return 'WHERE w.user_id = :userId
                AND w.watched_date IS NOT NULL
                AND w.source <> :deduced
                AND EXTRACT(YEAR FROM w.watched_date) = :year';
    }

    /**
     * @return array<string, mixed>
     */
    private function yearParams(User $user, int $year): array
    {
        return $this->baseParams($user) + ['year' => $year];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseParams(User $user): array
    {
        return [
            'userId' => (string) $user->getId(),
            'deduced' => WatchSource::CSV_RERATING->value,
        ];
    }
}
