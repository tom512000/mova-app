<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CabinetDto;
use App\Entity\CardCabinet;
use App\Entity\User;
use App\Repository\CardCabinetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * The account's standing in the game: its cabinet row, and the one write that has to exist
 * before any other can.
 *
 * The migration seeded a cabinet for every account that existed when the feature shipped,
 * but accounts registered afterwards arrive without one, and so does any account restored
 * from a backup taken before it. Rather than put a find-or-create branch at the top of every
 * write path — the daily grant, the pack opener, the set claim — there is one idempotent
 * upsert here that they all call first.
 */
final class CardCabinetService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardCabinetRepository $cabinets,
        private readonly CabinetClock $clock,
    ) {
    }

    /**
     * Pays the connection grant, once per Paris day.
     *
     * One conditional UPDATE, never a read followed by a write. The WHERE clause is the
     * idempotency: it only matches while the row still says today's grant has not been
     * taken, so two requests arriving together serialise on the row lock and the second
     * finds nothing to update. A check in PHP followed by a write would leave a gap between
     * the two halves of that rule wide enough for a double-clicked button.
     *
     * The amount is computed *inside* the statement because it depends on the streak the
     * same statement is incrementing — which is also why JetonTariff's ladder is written
     * twice, once here as SQL and once in PHP, with a test pinning them together.
     *
     * @return array{granted: bool, amount: int, balance: int, streakDays: int}
     */
    public function claimDailyGrant(User $user): array
    {
        $this->ensure($user);

        $row = $this->entityManager->getConnection()->executeQuery(
            'UPDATE card_cabinet SET
                streak_days = CASE WHEN last_daily_grant_on = :yesterday THEN streak_days + 1 ELSE 1 END,
                balance = balance + LEAST(:max, :base + :step *
                    (CASE WHEN last_daily_grant_on = :yesterday THEN streak_days ELSE 0 END)),
                lifetime_earned = lifetime_earned + LEAST(:max, :base + :step *
                    (CASE WHEN last_daily_grant_on = :yesterday THEN streak_days ELSE 0 END)),
                last_daily_grant_on = :today
            WHERE user_id = :userId
                AND (last_daily_grant_on IS NULL OR last_daily_grant_on < :today)
            RETURNING balance, streak_days',
            [
                'userId' => (string) $user->getId(),
                'today' => $this->clock->today()->format('Y-m-d'),
                'yesterday' => $this->clock->yesterday()->format('Y-m-d'),
                'base' => JetonTariff::DAILY_BASE,
                'step' => JetonTariff::DAILY_STREAK_STEP,
                'max' => JetonTariff::DAILY_MAX,
            ]
        )->fetchAssociative();

        if (false === $row) {
            // Already claimed today. Not an error — the client asks on every visit.
            $cabinet = $this->get($user);

            return [
                'granted' => false,
                'amount' => 0,
                'balance' => $cabinet->getBalance(),
                'streakDays' => $cabinet->getStreakDays(),
            ];
        }

        $streak = (int) $row['streak_days'];

        return [
            'granted' => true,
            'amount' => JetonTariff::dailyGrant($streak - 1),
            'balance' => (int) $row['balance'],
            'streakDays' => $streak,
        ];
    }

    /**
     * Credits jetons from somewhere other than a pack — a completed set, a won board.
     *
     * Kept here rather than spread across the services that award them so that every route
     * into the balance goes through one statement, and so `lifetime_earned` cannot drift
     * from what was actually paid.
     */
    public function credit(User $user, int $jetons): int
    {
        if ($jetons <= 0) {
            return $this->get($user)->getBalance();
        }

        $this->ensure($user);

        return (int) $this->entityManager->getConnection()->executeQuery(
            'UPDATE card_cabinet
            SET balance = balance + :jetons, lifetime_earned = lifetime_earned + :jetons
            WHERE user_id = :userId
            RETURNING balance',
            ['userId' => (string) $user->getId(), 'jetons' => $jetons]
        )->fetchOne();
    }

    /**
     * Guarantees the account has a cabinet row, and answers whether it had to make one.
     *
     * ON CONFLICT DO NOTHING rather than a read followed by an insert: two requests arriving
     * together for an account that has never opened the Cabinet would both see nothing and
     * both insert, and the second would fail on the unique constraint. Letting the database
     * arbitrate turns that race into a no-op.
     */
    public function ensure(User $user): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO card_cabinet (
                id, user_id, balance, lifetime_earned, lifetime_spent,
                free_packs_opened, paid_packs_opened, pity_super_rare, pity_legendary,
                streak_days, free_dup_jetons_today, catalogue_work_count, catalogue_card_count
            ) VALUES (:id, :userId, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)
            ON CONFLICT (user_id) DO NOTHING',
            [
                'id' => (string) new UuidV7(),
                'userId' => (string) $user->getId(),
            ]
        );
    }

    /**
     * The cabinet as the API sends it.
     *
     * $includeTill decides whether the money travels. A collection is worth showing on a
     * shared profile; the balance, the streak and what can be claimed belong to whoever is
     * doing the looking, not to the library being looked at, so they come back null there.
     */
    public function toDto(User $user, bool $includeTill): CabinetDto
    {
        $cabinet = $this->get($user);
        $owned = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM card WHERE user_id = :userId AND copies > 0',
            ['userId' => (string) $user->getId()]
        )->fetchOne();

        $today = $this->clock->today();
        $grantTaken = $cabinet->getLastDailyGrantOn()?->format('Y-m-d') === $today->format('Y-m-d');
        $salvagedToday = $cabinet->getFreeDupJetonsOn()?->format('Y-m-d') === $today->format('Y-m-d')
            ? $cabinet->getFreeDupJetonsToday()
            : 0;

        return new CabinetDto(
            ownerDisplayName: $user->getDisplayName(),
            workCount: $cabinet->getCatalogueWorkCount(),
            cardCount: $cabinet->getCatalogueCardCount(),
            ownedCount: $owned,
            minimumWorks: CardPackOpener::MINIMUM_WORKS,
            unlocked: $cabinet->getCatalogueWorkCount() >= CardPackOpener::MINIMUM_WORKS,
            catalogueBuiltAt: $cabinet->getCatalogueBuiltAt()?->format(\DATE_ATOM),
            balance: $includeTill ? $cabinet->getBalance() : null,
            lifetimeEarned: $includeTill ? $cabinet->getLifetimeEarned() : null,
            lifetimeSpent: $includeTill ? $cabinet->getLifetimeSpent() : null,
            packsOpened: $includeTill ? $cabinet->getPacksOpened() : null,
            streakDays: $includeTill ? $cabinet->getStreakDays() : null,
            dailyGrantAvailable: $includeTill ? !$grantTaken : null,
            nextGrantAt: $includeTill ? $this->clock->nextDayStart()->format(\DATE_ATOM) : null,
            freeSalvageLeft: $includeTill
                ? max(0, JetonTariff::FREE_DUPLICATE_DAILY_CAP - $salvagedToday)
                : null,
        );
    }

    /**
     * The cabinet, creating it first if the account has never had one.
     *
     * The entity is detached from the insert above — ensure() writes through SQL, so the
     * unit of work has never seen the row — which is why this reads afterwards rather than
     * returning something ensure() built.
     */
    public function get(User $user): CardCabinet
    {
        $cabinet = $this->cabinets->findOneByUser($user);
        if (null !== $cabinet) {
            return $cabinet;
        }

        $this->ensure($user);

        $cabinet = $this->cabinets->findOneByUser($user);
        if (null === $cabinet) {
            // Unreachable: ensure() either inserted the row or found it already there.
            throw new \LogicException('Le cabinet n\'a pas pu être créé.');
        }

        return $cabinet;
    }
}
