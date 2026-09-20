<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\CardPackKind;
use App\Repository\CardPackOpeningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pack, opened.
 *
 * It earns its table for four reasons rather than one. A connection dropped mid-animation
 * must cost the *animation* and not the cards, so the client can ask for the last few
 * openings and replay one. It is the only pack-granularity history there is. It is what
 * economy tuning will actually read, since there is no jeton ledger. And it dates the
 * pack-counting feats without making them count something derived.
 *
 * `cards` is a JSON list of card id strings, in the order they were dealt — the idiom
 * GameSession already establishes for its guesses and boards, including the caveat that
 * what round-trips through a JSON column is exactly what json_encode produced, so these are
 * strings and comparisons against them are string comparisons.
 */
#[ORM\Entity(repositoryClass: CardPackOpeningRepository::class)]
#[ORM\Table(name: 'card_pack_opening')]
#[ORM\Index(name: 'idx_card_pack_opening_user_opened', columns: ['user_id', 'opened_at'])]
class CardPackOpening
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: CardPackKind::class)]
    private CardPackKind $kind;

    /** What it cost, copied from the tariff at the time rather than looked up later. */
    #[ORM\Column]
    private int $cost;

    /** What the duplicates in it paid back, after the daily cap was applied. */
    #[ORM\Column]
    private int $jetonsEarned = 0;

    /**
     * The cards dealt, in order, as UUID strings.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $cards = [];

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    /**
     * @param list<string> $cards
     */
    public function __construct(User $user, CardPackKind $kind, int $cost, int $jetonsEarned, array $cards)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->kind = $kind;
        $this->cost = $cost;
        $this->jetonsEarned = $jetonsEarned;
        $this->cards = $cards;
        $this->openedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): CardPackKind
    {
        return $this->kind;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getJetonsEarned(): int
    {
        return $this->jetonsEarned;
    }

    /**
     * @return list<string>
     */
    public function getCards(): array
    {
        return $this->cards;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }
}
