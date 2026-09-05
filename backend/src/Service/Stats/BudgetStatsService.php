<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\DTO\Stats\BudgetBandDto;
use App\DTO\Stats\BudgetStatsDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ratings against what the film cost to make.
 *
 * The bands are wide and few on purpose. TMDB's budgets are crowd-entered and the small ones
 * are the least reliable of the lot — a film with no budget on record often ends up with a
 * round number somebody guessed — so a finer grid would draw a precise-looking curve on top
 * of numbers that do not deserve it. Four brackets say what can honestly be said.
 *
 * A zero budget is read as "not recorded", not as "made for nothing", which is what it means
 * in practice on TMDB. Those works are counted separately and reported alongside, because a
 * block computed from three quarters of the library must say so rather than let the bars
 * imply they cover it.
 */
final class BudgetStatsService
{
    /**
     * Upper bounds in US dollars, exclusive. The band above the last one is open-ended, so
     * four bounds' worth of brackets come out of three numbers.
     */
    private const BOUNDS = [5_000_000, 30_000_000, 100_000_000];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getBudgetStats(User $user): BudgetStatsDto
    {
        $connection = $this->entityManager->getConnection();

        $rows = $connection->executeQuery(
            'SELECT
                '.$this->bandExpression().' AS band,
                COUNT(DISTINCT m.id) AS movie_count,
                AVG(w.rating) AS average_rating
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.user_id = :userId AND m.budget IS NOT NULL AND m.budget > 0
            GROUP BY 1
            ORDER BY 1',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        $byBand = [];
        foreach ($rows as $row) {
            $byBand[(int) $row['band']] = $row;
        }

        $bands = [];
        foreach ($this->bandBounds() as $index => [$min, $max]) {
            $row = $byBand[$index] ?? null;

            $bands[] = new BudgetBandDto(
                minBudget: $min,
                maxBudget: $max,
                movieCount: null !== $row ? (int) $row['movie_count'] : 0,
                // Null rather than zero for a bracket nobody watched: zero is a rating, and
                // an empty bracket has not earned one.
                averageRating: null !== $row && null !== $row['average_rating']
                    ? round((float) $row['average_rating'], 2)
                    : null,
            );
        }

        $withoutBudget = (int) $connection->executeQuery(
            'SELECT COUNT(DISTINCT m.id)
            FROM watch w
            JOIN movie m ON m.id = w.movie_id
            WHERE w.user_id = :userId AND (m.budget IS NULL OR m.budget = 0)',
            ['userId' => (string) $user->getId()]
        )->fetchOne();

        return new BudgetStatsDto(bands: $bands, worksWithoutBudget: $withoutBudget);
    }

    /**
     * The CASE that sorts a row into its bracket.
     *
     * Interpolated rather than bound, because a parameter cannot appear where this needs to
     * and because BOUNDS is a private list of integer literals — there is no user input
     * anywhere near this string. Cast to int all the same, so a future edit to BOUNDS cannot
     * turn it into one.
     */
    private function bandExpression(): string
    {
        $case = 'CASE';
        foreach (self::BOUNDS as $index => $bound) {
            $case .= sprintf(' WHEN m.budget < %d THEN %d', (int) $bound, $index);
        }

        return $case.sprintf(' ELSE %d END', \count(self::BOUNDS));
    }

    /**
     * @return list<array{int, int|null}>
     */
    private function bandBounds(): array
    {
        $bounds = [];
        $previous = 0;
        foreach (self::BOUNDS as $bound) {
            $bounds[] = [$previous, $bound];
            $previous = $bound;
        }
        $bounds[] = [$previous, null];

        return $bounds;
    }
}
