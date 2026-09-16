<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\Person\PersonSummaryDto;
use App\Entity\Enum\CreditRole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PersonMapper
{
    public function __construct(
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * Straight from a raw row rather than an entity: a directory card reads six aggregates
     * the person row does not hold, so there is no entity worth hydrating for it.
     *
     * @param array<string, mixed> $row from PersonRepository::search()
     */
    public function toSummaryDto(array $row): PersonSummaryDto
    {
        return new PersonSummaryDto(
            id: (string) $row['id'],
            name: (string) $row['name'],
            // w185 rather than the w300 a person's own page gets: these are thumbnails in a
            // grid, never shown at portrait size.
            profileUrl: null !== $row['profile_path'] ? "{$this->imageBaseUrl}/w185{$row['profile_path']}" : null,
            roles: $this->rolesOf($row['roles']),
            watchedCount: (int) $row['watched_count'],
            watchlistCount: (int) $row['watchlist_count'],
            workCount: (int) $row['work_count'],
            averageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            lastWatchedDate: null !== $row['last_watched_date'] ? (string) $row['last_watched_date'] : null,
        );
    }

    /**
     * The jobs arrive as one comma-joined string, carrying a job once per work it was held
     * on — the query aggregates over works, and no DBAL type would convert an array literal
     * on a raw query anyway. Deduplicated here, then ordered the way a credit block is.
     *
     * @return list<CreditRole>
     */
    private function rolesOf(mixed $aggregate): array
    {
        $roles = array_values(array_unique(array_filter(array_map(
            static fn (string $value) => CreditRole::tryFrom(trim($value)),
            explode(',', (string) $aggregate)
        )), \SORT_REGULAR));

        return CreditRole::sortByCreditOrder($roles);
    }
}
