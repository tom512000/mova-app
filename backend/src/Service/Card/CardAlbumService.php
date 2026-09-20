<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardFacetsDto;
use App\DTO\Card\CardListResponse;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CardSubject;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reading the album.
 *
 * Raw DBAL rather than the repository, for the reason every other listing in this codebase
 * gives: this pages, filters and counts over several thousand rows per account, and the unit
 * of work has nothing to contribute to a grid of names and tiers.
 *
 * Filters are forgiving by convention — an unknown sort or an out-of-range page falls back
 * to a default rather than answering 422, because these arrive from the address bar.
 */
final class CardAlbumService
{
    public const DEFAULT_PER_PAGE = 60;
    public const MAX_PER_PAGE = 120;

    /**
     * What each sort means, as SQL.
     *
     * `rank` is the default and is the catalogue order the rebuild computed: best shelf
     * first, by score inside a shelf. It is stable between visits, which is the property an
     * album needs — winning a card should fill a slot, not reshuffle the page.
     */
    private const SORTS = [
        'rank' => 'c.catalogue_rank ASC',
        'recent' => 'c.first_owned_at DESC NULLS LAST, c.catalogue_rank ASC',
        'score' => 'c.score DESC, c.catalogue_rank ASC',
        'name' => 'c.label ASC, c.catalogue_rank ASC',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardMapper $mapper,
    ) {
    }

    /**
     * @param array{subject?: ?string, rarity?: ?string, owned?: ?bool, q?: ?string, sort?: ?string} $criteria
     */
    public function list(User $user, array $criteria, int $page, int $perPage): CardListResponse
    {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        [$where, $parameters, $types] = $this->filters($user, $criteria);
        $order = self::SORTS[$criteria['sort'] ?? 'rank'] ?? self::SORTS['rank'];

        $connection = $this->entityManager->getConnection();

        $total = (int) $connection->executeQuery(
            "SELECT COUNT(*) FROM card c WHERE {$where}",
            $parameters,
            $types
        )->fetchOne();

        $rows = $connection->executeQuery(
            "SELECT c.id, c.subject, c.label, c.image_path, c.release_year, c.work_count,
                    c.rarity, c.owned_rarity, c.percentile, c.copies, c.in_catalogue,
                    c.showcase_position
            FROM card c
            WHERE {$where}
            ORDER BY {$order}
            LIMIT :limit OFFSET :offset",
            array_merge($parameters, ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]),
            array_merge($types, ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER])
        )->fetchAllAssociative();

        return new CardListResponse(
            items: array_map(fn (array $row) => $this->mapper->fromRow($row), $rows),
            total: $total,
            page: $page,
            perPage: $perPage,
            // Only on an unfiltered read: a tally of what a filter already excluded is noise.
            counts: $this->isUnfiltered($criteria) ? $this->rarityCounts($user) : null,
        );
    }

    public function facets(User $user): CardFacetsDto
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT c.subject, c.rarity, c.in_catalogue,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE c.copies > 0) AS owned
            FROM card c
            WHERE c.user_id = :userId
            GROUP BY c.subject, c.rarity, c.in_catalogue',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        $byRarity = $ownedByRarity = $bySubject = $ownedBySubject = [];
        $total = $owned = $relics = 0;

        foreach ($rows as $row) {
            $count = (int) $row['total'];
            $ownedCount = (int) $row['owned'];

            if (!$row['in_catalogue']) {
                // Relics are owned but undrawable. They are counted apart rather than folded
                // into the tiers, or an album would claim to hold cards no pack can produce.
                $relics += $ownedCount;
                continue;
            }

            $rarity = (string) $row['rarity'];
            $subject = (string) $row['subject'];

            $byRarity[$rarity] = ($byRarity[$rarity] ?? 0) + $count;
            $ownedByRarity[$rarity] = ($ownedByRarity[$rarity] ?? 0) + $ownedCount;
            $bySubject[$subject] = ($bySubject[$subject] ?? 0) + $count;
            $ownedBySubject[$subject] = ($ownedBySubject[$subject] ?? 0) + $ownedCount;
            $total += $count;
            $owned += $ownedCount;
        }

        return new CardFacetsDto(
            byRarity: $this->inLadderOrder($byRarity),
            ownedByRarity: $this->inLadderOrder($ownedByRarity),
            bySubject: $bySubject,
            ownedBySubject: $ownedBySubject,
            total: $total,
            owned: $owned,
            outOfCatalogue: $relics,
        );
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function filters(User $user, array $criteria): array
    {
        $where = 'c.user_id = :userId';
        $parameters = ['userId' => (string) $user->getId()];
        $types = [];

        // An unknown value is ignored rather than refused: these arrive from the address bar.
        $subject = CardSubject::tryFrom((string) ($criteria['subject'] ?? ''));
        if (null !== $subject) {
            $where .= ' AND c.subject = :subject';
            $parameters['subject'] = $subject->value;
        }

        $rarity = CardRarity::tryFrom((string) ($criteria['rarity'] ?? ''));
        if (null !== $rarity) {
            // Filtering on what the collection *shows*, which is the frozen tier where there
            // is one. Filtering on the live tier would hide a Légendaire from its own shelf
            // the moment the catalogue moved under it.
            $where .= ' AND COALESCE(c.owned_rarity, c.rarity) = :rarity';
            $parameters['rarity'] = $rarity->value;
        }

        if (null !== ($criteria['owned'] ?? null)) {
            $where .= true === $criteria['owned'] ? ' AND c.copies > 0' : ' AND c.copies = 0';
        }

        $query = trim((string) ($criteria['q'] ?? ''));
        if ('' !== $query) {
            $where .= ' AND c.label ILIKE :query';
            $parameters['query'] = '%'.$query.'%';
        }

        return [$where, $parameters, $types];
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function isUnfiltered(array $criteria): bool
    {
        return null === ($criteria['subject'] ?? null)
            && null === ($criteria['rarity'] ?? null)
            && null === ($criteria['owned'] ?? null)
            && '' === trim((string) ($criteria['q'] ?? ''));
    }

    /**
     * @return array<string, int>
     */
    private function rarityCounts(User $user): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT COALESCE(c.owned_rarity, c.rarity) AS rarity, COUNT(*) AS n
            FROM card c WHERE c.user_id = :userId
            GROUP BY 1',
            ['userId' => (string) $user->getId()]
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['rarity']] = (int) $row['n'];
        }

        return $this->inLadderOrder($counts);
    }

    /**
     * Commune first, Légendaire last, with every tier present even at zero.
     *
     * A client that has to decide whether a missing key means "none" or "not counted" will
     * eventually decide wrong, and a filter bar that grows a row when the first card of a
     * tier is pulled reads as a bug.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function inLadderOrder(array $counts): array
    {
        $ordered = [];
        foreach (CardRarity::cases() as $rarity) {
            $ordered[$rarity->value] = $counts[$rarity->value] ?? 0;
        }

        return $ordered;
    }
}
