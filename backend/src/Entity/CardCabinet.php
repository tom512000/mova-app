<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\CardCabinetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's standing in the card game: its jetons, its counters, and the bookkeeping
 * that makes the daily grants idempotent.
 *
 * Its own table rather than columns on User, for the reason LetterboxdSyncState already
 * gives: User is the identity and security entity, and a feature's running state does not
 * belong on it. Bolting a balance and two pity counters onto the row that carries the
 * password hash would mean every pack opening takes a write lock on the account.
 *
 * Every account gets a row, created by the migration for existing ones and alongside
 * registration afterwards, so no write path has to carry a find-or-create branch.
 *
 * The three catalogue_* columns are a cache with a purpose: `catalogueWorkCount` is what the
 * 500-work gate reads, so the gate costs no query at request time, and `catalogueBuiltAt` is
 * what the lazy staleness check compares against before dispatching a rebuild.
 *
 * There are no setters, and that is the point. Every mutation here is a conditional UPDATE
 * that decides *and* applies in one statement — the daily grant only lands when the row
 * still says it has not been claimed today, the balance is only debited when it is high
 * enough. Read-then-write through an entity would put a race between the two halves of each
 * of those rules, so the only writer is SQL and this class is the read model.
 */
#[ORM\Entity(repositoryClass: CardCabinetRepository::class)]
#[ORM\Table(name: 'card_cabinet')]
#[ORM\UniqueConstraint(name: 'uniq_card_cabinet_user', fields: ['user'])]
class CardCabinet
{
    use HasUuid;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $balance = 0;

    /**
     * Running totals, kept instead of a transactions table.
     *
     * The deliberate omission: there is no jeton ledger. These two plus the pack history in
     * CardPackOpening cover economy tuning and "where did my jetons go" without a row per
     * movement. If the economy ever needs real debugging this is the first thing to reverse,
     * which is why it is written down rather than left to be discovered.
     */
    #[ORM\Column]
    private int $lifetimeEarned = 0;

    #[ORM\Column]
    private int $lifetimeSpent = 0;

    #[ORM\Column]
    private int $freePacksOpened = 0;

    #[ORM\Column]
    private int $paidPacksOpened = 0;

    /**
     * Cards drawn from paying packs since the last Super Rare, and since the last Légendaire.
     *
     * Hard pity, no soft ramp: the counters force a tier at an exact threshold and reset. It
     * is trivially testable ("fires at 30, not at 29") and trivially explicable to a player,
     * which a rising probability curve is neither. Free packs never advance either counter —
     * see CardPackKind's docblock for why that rule is load-bearing rather than stingy.
     */
    #[ORM\Column]
    private int $pitySuperRare = 0;

    #[ORM\Column]
    private int $pityLegendary = 0;

    /**
     * The Paris day the connection grant was last claimed, and how many days in a row.
     *
     * A date and not a timestamp, because the grant is once per day and not once per 24
     * hours — and the day is Paris's, the same midnight the daily game boards already turn
     * over on. Two different "todays" in one app is a bug waiting to be filed, which is why
     * CabinetClock owns the timezone and every consumer asks it.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastDailyGrantOn = null;

    #[ORM\Column]
    private int $streakDays = 0;

    /**
     * The daily cap on jetons salvaged from free-pack duplicates, and the day it applies to.
     *
     * This pair is what makes "unlimited free packs" survivable arithmetic rather than a
     * hope. Free packs stay unlimited and keep dealing cards forever; what stops after the
     * cap is the money. Without it, a few hundred packs of clicking buys a paying pack, and
     * the whole point of the paying packs evaporates.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $freeDupJetonsOn = null;

    #[ORM\Column]
    private int $freeDupJetonsToday = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $catalogueBuiltAt = null;

    /** Distinct works watched at the last rebuild — the number the 500-work gate reads. */
    #[ORM\Column]
    private int $catalogueWorkCount = 0;

    #[ORM\Column]
    private int $catalogueCardCount = 0;

    public function __construct(User $user)
    {
        $this->initialiseUuid();
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getLifetimeEarned(): int
    {
        return $this->lifetimeEarned;
    }

    public function getLifetimeSpent(): int
    {
        return $this->lifetimeSpent;
    }

    public function getFreePacksOpened(): int
    {
        return $this->freePacksOpened;
    }

    public function getPaidPacksOpened(): int
    {
        return $this->paidPacksOpened;
    }

    public function getPacksOpened(): int
    {
        return $this->freePacksOpened + $this->paidPacksOpened;
    }

    public function getPitySuperRare(): int
    {
        return $this->pitySuperRare;
    }

    public function getPityLegendary(): int
    {
        return $this->pityLegendary;
    }

    public function getLastDailyGrantOn(): ?\DateTimeImmutable
    {
        return $this->lastDailyGrantOn;
    }

    public function getStreakDays(): int
    {
        return $this->streakDays;
    }

    public function getFreeDupJetonsOn(): ?\DateTimeImmutable
    {
        return $this->freeDupJetonsOn;
    }

    public function getFreeDupJetonsToday(): int
    {
        return $this->freeDupJetonsToday;
    }

    public function getCatalogueBuiltAt(): ?\DateTimeImmutable
    {
        return $this->catalogueBuiltAt;
    }

    public function getCatalogueWorkCount(): int
    {
        return $this->catalogueWorkCount;
    }

    public function getCatalogueCardCount(): int
    {
        return $this->catalogueCardCount;
    }
}
