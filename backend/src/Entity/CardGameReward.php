<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\CardGameRewardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The jetons a won daily board paid out.
 *
 * This is what wires the eight existing games into the Cabinet instead of leaving them
 * beside it, and it costs almost nothing: every win in FilmGuessGame already funnels through
 * one place, so a won daily session inserts a row here on its way past.
 *
 * The unique constraint on the session is the idempotency, and it rides on one that already
 * exists: GameSession's uniq_game_session_daily already guarantees one run per board per
 * day, so one row per session is one payout per board per day without this class having to
 * know what a day is. A replayed message, a double-submitted last guess or a retried request
 * hits the constraint rather than paying twice.
 *
 * Infinite runs pay nothing, deliberately. They can be restarted without limit, so paying
 * them would be paying a loop.
 */
#[ORM\Entity(repositoryClass: CardGameRewardRepository::class)]
#[ORM\Table(name: 'card_game_reward')]
#[ORM\UniqueConstraint(name: 'uniq_card_game_reward_session', fields: ['gameSession'])]
class CardGameReward
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\OneToOne(targetEntity: GameSession::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameSession $gameSession;

    #[ORM\Column]
    private int $jetons;

    #[ORM\Column]
    private \DateTimeImmutable $awardedAt;

    public function __construct(User $user, GameSession $gameSession, int $jetons)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->gameSession = $gameSession;
        $this->jetons = $jetons;
        $this->awardedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGameSession(): GameSession
    {
        return $this->gameSession;
    }

    public function getJetons(): int
    {
        return $this->jetons;
    }

    public function getAwardedAt(): \DateTimeImmutable
    {
        return $this->awardedAt;
    }
}
