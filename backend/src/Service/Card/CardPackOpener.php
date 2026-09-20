<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\PackCardDto;
use App\DTO\Card\PackResultDto;
use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;
use App\Entity\User;
use App\Exception\CardException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Opens a pack.
 *
 * The whole thing is one transaction that begins by taking a row lock on the cabinet. That
 * lock is the only one in the feature and it earns its place: it serialises two openings
 * arriving together for the same account, which is what keeps a balance from being spent
 * twice and a pity counter from being reset by a card it never saw.
 *
 * **Drawing a card is two steps, and the split is the point.** PHP picks a *tier* — six
 * numbers and a cumulative walk over them, pure and testable at every boundary. SQL then
 * draws one card *of that tier*, weighted, and returns exactly one row. Nothing enumerates
 * the catalogue: the alternative, loading several thousand cards to pick one, is the thing
 * the whole materialised design exists to avoid.
 *
 * **The weighted draw.** `random() ^ (1 / weight)` ordered descending is Efraimidis–Spirakis
 * one-pass weighted sampling: a larger weight pushes the sort key closer to 1, so the top
 * row is selected with probability proportional to its weight. Here the weight favours cards
 * nobody owns yet, which is the standard kindness of the genre and the thing that makes a
 * two-thousand-card Commune band actually completable. Honestly stated: this is an index
 * scan over one tier's partition plus a top-1 sort, not O(1). It is sub-millisecond at this
 * size, and if it ever stopped being so the fallback is a cached per-tier count and a random
 * OFFSET.
 *
 * **Writes are raw SQL, not entities.** One UPDATE per card increments `copies`, stamps
 * `first_owned_at` and freezes `owned_rarity`, and its RETURNING clause is how the opener
 * learns whether the card was new. Hydrating an entity to add one to an integer would be a
 * read, a unit-of-work registration and a flush for what one atomic statement does — and
 * there would still be no way to tell new from duplicate without a second read.
 */
final class CardPackOpener
{
    /**
     * Works watched before the Cabinet opens at all.
     *
     * Read off card_cabinet.catalogue_work_count, which the rebuild maintains, so the gate
     * costs no query. Enforced here and nowhere else: reads answer with a locked, empty
     * payload instead, because gating them would make every card route fail against the
     * two-work fixture the read smoke test uses.
     */
    public const MINIMUM_WORKS = 500;

    /** How much likelier an unowned card is to be drawn than one already in the album. */
    private const UNOWNED_WEIGHT = 2.5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardCabinetService $cabinets,
        private readonly CardRandomSourceInterface $random,
        private readonly CardMapper $mapper,
        private readonly CabinetClock $clock,
    ) {
    }

    public function open(User $user, CardPackKind $kind): PackResultDto
    {
        $this->cabinets->ensure($user);

        $userId = (string) $user->getId();
        $today = $this->clock->today()->format('Y-m-d');
        $price = JetonTariff::priceOf($kind);

        /** @var PackResultDto $result */
        $result = $this->entityManager->getConnection()->transactional(
            function (Connection $connection) use ($userId, $kind, $price, $today): PackResultDto {
                $cabinet = $this->lockCabinet($connection, $userId);

                $this->assertGateOpen((int) $cabinet['catalogue_work_count']);
                $this->assertAffordable((int) $cabinet['balance'], $price);

                $pitySuperRare = (int) $cabinet['pity_super_rare'];
                $pityLegendary = (int) $cabinet['pity_legendary'];

                // The cap is per Paris day, so a stored total from yesterday is spent.
                $salvagedToday = ($cabinet['free_dup_jetons_on'] ?? null) === $today
                    ? (int) $cabinet['free_dup_jetons_today']
                    : 0;

                $cards = [];
                $drawnIds = [];
                $jetonsEarned = 0;
                $capReached = false;
                $slots = $kind->cardCount();

                for ($slot = 1; $slot <= $slots; ++$slot) {
                    $isGuaranteed = $slot === $slots;
                    $pityFloor = $kind->buildsPity()
                        ? PackOdds::pityFloor($pitySuperRare, $pityLegendary)
                        : null;

                    $tier = $this->rollTier($kind, $isGuaranteed, $pityFloor);
                    $row = $this->draw($connection, $userId, $tier, $drawnIds, $kind);

                    $drawnIds[] = (string) $row['id'];
                    $owned = $this->claim($connection, (string) $row['id']);
                    $copies = (int) $owned['copies'];
                    $isNew = 1 === $copies;

                    $jetons = 0;
                    if (!$isNew) {
                        $jetons = JetonTariff::duplicateValue(CardRarity::from((string) $row['rarity']), $copies);

                        if ($kind->isFree()) {
                            $allowance = max(0, JetonTariff::FREE_DUPLICATE_DAILY_CAP - $salvagedToday);
                            if ($jetons > $allowance) {
                                $capReached = true;
                                $jetons = $allowance;
                            }
                            $salvagedToday += $jetons;
                        }
                    }

                    $jetonsEarned += $jetons;

                    // Pity counts cards, not packs, and only from packs that pay for it.
                    if ($kind->buildsPity()) {
                        $pitySuperRare = $tier->rank() >= CardRarity::SUPER_RARE->rank() ? 0 : $pitySuperRare + 1;
                        $pityLegendary = CardRarity::LEGENDARY === $tier ? 0 : $pityLegendary + 1;
                    }

                    $cards[] = new PackCardDto(
                        card: $this->mapper->fromRow(array_merge($row, [
                            'copies' => $copies,
                            'owned_rarity' => $owned['owned_rarity'],
                        ])),
                        isNew: $isNew,
                        copies: $copies,
                        jetons: $jetons,
                        wasGuaranteed: $isGuaranteed,
                        wasPity: null !== $pityFloor,
                    );
                }

                $balance = $this->settle(
                    $connection,
                    $userId,
                    $kind,
                    $price,
                    $jetonsEarned,
                    $pitySuperRare,
                    $pityLegendary,
                    $today,
                    $salvagedToday
                );

                return $this->record($connection, $userId, $kind, $price, $jetonsEarned, $balance, $capReached, $cards);
            }
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function lockCabinet(Connection $connection, string $userId): array
    {
        $cabinet = $connection->executeQuery(
            'SELECT balance, pity_super_rare, pity_legendary, catalogue_work_count,
                    free_dup_jetons_on, free_dup_jetons_today
            FROM card_cabinet WHERE user_id = :userId FOR UPDATE',
            ['userId' => $userId]
        )->fetchAssociative();

        if (false === $cabinet) {
            // ensure() ran before the transaction opened, so this cannot happen short of the
            // account being deleted underneath the request.
            throw new CardException('Le Cabinet n\'est pas disponible pour ce compte.');
        }

        return $cabinet;
    }

    private function assertGateOpen(int $workCount): void
    {
        if ($workCount >= self::MINIMUM_WORKS) {
            return;
        }

        throw new CardException(sprintf(
            'Le Cabinet ouvre à %d œuvres vues. Il t\'en manque %d.',
            self::MINIMUM_WORKS,
            self::MINIMUM_WORKS - $workCount
        ));
    }

    private function assertAffordable(int $balance, int $price): void
    {
        if ($balance >= $price) {
            return;
        }

        throw new CardException(sprintf('Il te manque %d jetons pour ce paquet.', $price - $balance));
    }

    /**
     * Picks the tier for one slot: the pack's table, narrowed to the guaranteed floor and to
     * whatever pity has come due, whichever is higher.
     *
     * A floor only ever raises. Taking the better of the two rather than the later one is
     * what stops a Rare guarantee from cancelling a Légendaire the counters already owed.
     */
    private function rollTier(CardPackKind $kind, bool $isGuaranteed, ?CardRarity $pityFloor): CardRarity
    {
        $odds = $isGuaranteed ? PackOdds::forGuaranteedSlot($kind) : PackOdds::forKind($kind);

        $floor = match (true) {
            $isGuaranteed && null !== $pityFloor => $pityFloor->rank() > $kind->guaranteedFloor()->rank()
                ? $pityFloor
                : $kind->guaranteedFloor(),
            null !== $pityFloor => $pityFloor,
            default => null,
        };

        if (null !== $floor) {
            $odds = PackOdds::atOrAbove($odds, $floor);
        }

        return PackOdds::roll($odds, $this->random->int(0, PackOdds::total($odds) - 1));
    }

    /**
     * Draws one card of a tier, favouring cards not already owned.
     *
     * Steps down a tier when a band comes back empty — a catalogue small enough that the
     * band rounded to one card, and that card already drawn earlier in this very pack. That
     * beats both alternatives: refusing after the balance was debited, and dealing the same
     * card twice in one pack.
     *
     * @param list<string> $drawnIds
     *
     * @return array<string, mixed>
     */
    private function draw(Connection $connection, string $userId, CardRarity $tier, array $drawnIds, CardPackKind $kind): array
    {
        $excluded = $kind->isFree()
            ? array_values(array_map(
                static fn (CardRarity $rarity) => $rarity->value,
                array_filter(CardRarity::cases(), static fn (CardRarity $rarity) => !$rarity->isInFreePool())
            ))
            : [];

        // Both list clauses are added only when they have something in them. An empty array
        // bound through DBAL expands to `IN (NULL)`, which is valid SQL that quietly matches
        // nothing — exactly the wrong answer here, since an empty exclusion list means
        // "exclude nothing" rather than "allow nothing".
        $conditions = '';
        $parameters = ['userId' => $userId];
        $types = [];

        if ([] !== $excluded) {
            $conditions .= ' AND c.rarity NOT IN (:excluded)';
            $parameters['excluded'] = $excluded;
            $types['excluded'] = ArrayParameterType::STRING;
        }

        if ([] !== $drawnIds) {
            $conditions .= ' AND c.id NOT IN (:drawn)';
            $parameters['drawn'] = $drawnIds;
            $types['drawn'] = ArrayParameterType::STRING;
        }

        // The weight is interpolated and everything else is bound: it is a float constant
        // declared in this class, never a value off a request.
        $weight = sprintf(
            '(CASE WHEN c.copies = 0 THEN %F ELSE 1.0 END)',
            self::UNOWNED_WEIGHT
        );

        for ($candidate = $tier; null !== $candidate; $candidate = $candidate->below()) {
            // The second guard on the free pool. PackOdds already gives these tiers zero, and
            // this makes a mistyped odds row unable to leak a Légendaire out of a free pack.
            if ($kind->isFree() && !$candidate->isInFreePool()) {
                continue;
            }

            $row = $connection->executeQuery(
                "SELECT c.id, c.subject, c.label, c.image_path, c.release_year, c.work_count,
                        c.rarity, c.owned_rarity, c.percentile, c.copies, c.in_catalogue,
                        c.showcase_position
                FROM card c
                WHERE c.user_id = :userId
                    AND c.in_catalogue
                    AND c.rarity = :rarity{$conditions}
                ORDER BY random() ^ (1.0 / {$weight}) DESC
                LIMIT 1",
                array_merge($parameters, ['rarity' => $candidate->value]),
                $types
            )->fetchAssociative();

            if (false !== $row) {
                return $row;
            }
        }

        // Every tier down to Commune came back empty. Throwing rolls the transaction back,
        // so the balance is untouched — the refusal costs nothing.
        throw new CardException('Ce paquet n\'a rien à distribuer : le catalogue est trop petit.');
    }

    /**
     * Records one copy.
     *
     * One statement for the new card and the duplicate alike, and RETURNING is how the caller
     * tells them apart. COALESCE on the two frozen columns is what makes them write-once:
     * `owned_rarity` keeps what the first pull was worth however far the live tier drifts
     * afterwards, which is the promise the whole two-column design exists to keep.
     *
     * @return array<string, mixed>
     */
    private function claim(Connection $connection, string $cardId): array
    {
        $row = $connection->executeQuery(
            'UPDATE card
            SET copies = copies + 1,
                first_owned_at = COALESCE(first_owned_at, NOW()),
                owned_rarity = COALESCE(owned_rarity, rarity)
            WHERE id = :id
            RETURNING copies, owned_rarity',
            ['id' => $cardId]
        )->fetchAssociative();

        if (false === $row) {
            throw new CardException('Cette carte a disparu du catalogue pendant l\'ouverture.');
        }

        return $row;
    }

    private function settle(
        Connection $connection,
        string $userId,
        CardPackKind $kind,
        int $price,
        int $jetonsEarned,
        int $pitySuperRare,
        int $pityLegendary,
        string $today,
        int $salvagedToday,
    ): int {
        $counter = $kind->isFree() ? 'free_packs_opened' : 'paid_packs_opened';

        $balance = $connection->executeQuery(
            "UPDATE card_cabinet SET
                balance = balance - :price + :earned,
                lifetime_spent = lifetime_spent + :price,
                lifetime_earned = lifetime_earned + :earned,
                {$counter} = {$counter} + 1,
                pity_super_rare = :pitySuperRare,
                pity_legendary = :pityLegendary,
                free_dup_jetons_on = :today,
                free_dup_jetons_today = :salvagedToday
            WHERE user_id = :userId
            RETURNING balance",
            [
                'userId' => $userId,
                'price' => $price,
                'earned' => $jetonsEarned,
                'pitySuperRare' => $pitySuperRare,
                'pityLegendary' => $pityLegendary,
                'today' => $today,
                'salvagedToday' => $salvagedToday,
            ]
        )->fetchOne();

        return (int) $balance;
    }

    /**
     * @param list<PackCardDto> $cards
     */
    private function record(
        Connection $connection,
        string $userId,
        CardPackKind $kind,
        int $price,
        int $jetonsEarned,
        int $balance,
        bool $capReached,
        array $cards,
    ): PackResultDto {
        $id = (string) new UuidV7();
        $openedAt = $this->clock->now();

        $connection->insert('card_pack_opening', [
            'id' => $id,
            'user_id' => $userId,
            'kind' => $kind->value,
            'cost' => $price,
            'jetons_earned' => $jetonsEarned,
            'cards' => json_encode(array_map(static fn (PackCardDto $card) => $card->card->id, $cards), \JSON_THROW_ON_ERROR),
            'opened_at' => $openedAt->format('Y-m-d H:i:s'),
        ]);

        return new PackResultDto(
            id: $id,
            kind: $kind,
            cards: $cards,
            cost: $price,
            jetonsEarned: $jetonsEarned,
            balance: $balance,
            dailyCapReached: $capReached,
            openedAt: $openedAt->format(\DATE_ATOM),
        );
    }
}
