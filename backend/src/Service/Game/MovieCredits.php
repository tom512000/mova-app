<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Movie;

/**
 * Reads a movie's credits in billing order. Both games lean on "who is top-billed", and
 * an unsorted collection makes that question unanswerable.
 */
final class MovieCredits
{
    /**
     * @return list<Credit>
     */
    public function byRole(Movie $movie, CreditRole $role): array
    {
        $credits = array_values(array_filter(
            $movie->getCredits()->toArray(),
            static fn (Credit $credit) => $credit->getRole() === $role
        ));

        // TMDB leaves the billing order null on some rows; those belong at the back.
        usort($credits, static fn (Credit $a, Credit $b) => ($a->getCastOrder() ?? \PHP_INT_MAX) <=> ($b->getCastOrder() ?? \PHP_INT_MAX));

        return $credits;
    }

    /**
     * @return list<string>
     */
    public function namesByRole(Movie $movie, CreditRole $role, ?int $limit = null): array
    {
        $credits = $this->byRole($movie, $role);
        if (null !== $limit) {
            $credits = \array_slice($credits, 0, $limit);
        }

        return array_map(static fn (Credit $credit) => $credit->getPerson()->getName(), $credits);
    }
}
