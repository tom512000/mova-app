<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\DivergenceStatsDto;
use App\DTO\Stats\DivergentWorkDto;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where the profile's ratings part company with TMDB's audience score.
 *
 * TMDB scores out of ten, the library out of five, so the public number is halved before the
 * two are subtracted. Everything else is a comparison of like with like: the score on a work
 * the profile actually rated, against the score everybody else gave the same work.
 *
 * Works with fewer than MINIMUM_VOTES ratings on TMDB are left out. Without that floor the
 * ranking fills with obscurities scored by a dozen people, where a two-star gap means only
 * that twelve strangers happened to disagree — the number stops measuring anything. The
 * floor and how many works cleared it travel with the answer, because a top five is not
 * readable without knowing what it was picked from.
 */
final class DivergenceStatsService
{
    /** How many TMDB ratings a work needs before its audience score is worth comparing to. */
    private const MINIMUM_VOTES = 50;

    /** Rows on each side. A top five reads at a glance; a top twenty is a spreadsheet. */
    private const SHOWN = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getDivergence(User $user): DivergenceStatsDto
    {
        // Every comparable work in one pass, widest positive gap first. Fetching the lot and
        // taking both ends in PHP costs one round trip instead of three, and hands back the
        // population size for free — where two LIMIT queries would need a third just to
        // count what they had been chosen from.
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT
                m.id AS movie_id,
                m.title AS title,
                AVG(w.rating) AS your_rating,
                m.tmdb_vote_average / 2.0 AS public_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.user_id = :userId
                AND m.tmdb_vote_average IS NOT NULL
                AND m.tmdb_vote_count >= :minimumVotes
            GROUP BY m.id, m.title, m.tmdb_vote_average
            HAVING AVG(w.rating) IS NOT NULL
            ORDER BY AVG(w.rating) - m.tmdb_vote_average / 2.0 DESC, m.title ASC',
            ['userId' => (string) $user->getId(), 'minimumVotes' => self::MINIMUM_VOTES],
            ['minimumVotes' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $works = array_map($this->toWork(...), $rows);

        // Split on the sign of the gap rather than by taking the two ends of the list. Slices
        // would overlap on a library too small to fill both columns, showing one work as a
        // favourite and as a disappointment at once — and a work scored exactly like the
        // public belongs in neither column, which the sign gets right and a slice does not.
        $liked = array_values(array_filter($works, static fn (DivergentWorkDto $work) => $work->gap > 0));
        $disliked = array_values(array_filter($works, static fn (DivergentWorkDto $work) => $work->gap < 0));

        return new DivergenceStatsDto(
            above: \array_slice($liked, 0, self::SHOWN),
            // The other end of the same ordering, reversed so the widest disagreement leads
            // this column too rather than trailing it.
            below: array_reverse(\array_slice($disliked, -self::SHOWN)),
            minimumVotes: self::MINIMUM_VOTES,
            comparableCount: \count($rows),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toWork(array $row): DivergentWorkDto
    {
        // Rounded first, subtracted second: a reader checks the arithmetic across the row,
        // and 5,0 − 3,0 has to give the +2,0 printed beside it rather than +1,96.
        $yours = round((float) $row['your_rating'], 2);
        $public = round((float) $row['public_rating'], 2);

        return new DivergentWorkDto(
            movieId: (string) $row['movie_id'],
            title: (string) $row['title'],
            yourRating: $yours,
            publicRating: $public,
            gap: round($yours - $public, 2),
        );
    }
}
