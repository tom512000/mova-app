<?php

declare(strict_types=1);

namespace App\DTO\Person;

use App\Entity\Enum\CreditRole;

/**
 * One card in the directory of people.
 *
 * The figures are all narrowed by whatever the listing was narrowed by: filtered on
 * "Réalisation", a name that also acts comes back with its directing tally and its
 * directing average, never a blend of the two. A page whose filter said one thing and whose
 * numbers said another would be worse than no filter at all.
 */
final readonly class PersonSummaryDto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $profileUrl,
        /**
         * Every job they hold on the works counted here, in credit-block order.
         *
         * @var list<CreditRole>
         */
        public array $roles,
        /** Distinct works of theirs watched — the same tally the dashboard rankings use. */
        public int $watchedCount,
        /** Works of theirs waiting in the watchlist, never watched. */
        public int $watchlistCount,
        /** Both of the above together: everything of theirs the library holds. */
        public int $workCount,
        /**
         * Averaged per work rather than per watch, so a film seen four times weighs once —
         * otherwise the figure would say more about rewatching habits than about them.
         */
        public ?float $averageRating,
        /** The last evening spent with one of their works. Revised notes do not count. */
        public ?string $lastWatchedDate,
    ) {
    }
}
