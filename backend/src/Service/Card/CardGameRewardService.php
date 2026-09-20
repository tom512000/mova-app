<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\CardGameReward;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\GameStatus;
use App\Entity\GameSession;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pays the eight daily boards into the Cabinet.
 *
 * This is what wires the games the app already had into the game it just grew, and it is
 * the best value in the whole economy: playing the app for a day earns several times what a
 * day of grinding free packs does, which is precisely the behaviour the free-pack design
 * needs somebody to have. Without it the Cabinet would sit beside the games rather than
 * being fed by them.
 *
 * Idempotency is the unique constraint on the session, not a check: a retried request, a
 * double-submitted last guess or a redelivered message hits the database and is refused
 * there. The constraint rides on one that already existed — game_session's own daily
 * uniqueness — so one row per session is one payout per board per day without this class
 * having to know what a day is.
 *
 * Infinite runs pay nothing. They can be restarted without limit, so paying them would be
 * paying a loop.
 */
final class CardGameRewardService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardCabinetService $cabinets,
    ) {
    }

    public function award(GameSession $session): void
    {
        if (GameStatus::WON !== $session->getStatus() || GameMode::DAILY !== $session->getMode()) {
            return;
        }

        $user = $session->getUser();

        try {
            $this->entityManager->persist(
                new CardGameReward($user, $session, JetonTariff::DAILY_GAME_WIN)
            );
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Already paid for this board. Nothing to do, and nothing wrong.
            return;
        }

        $jetons = JetonTariff::DAILY_GAME_WIN;

        // The sweep bonus needs no guard of its own: the insert above succeeds exactly once
        // per board, so the moment the count reaches eight happens exactly once per day.
        if ($this->boardsWonToday($session) === \count(GameKind::cases())) {
            $jetons += JetonTariff::DAILY_SWEEP_BONUS;
        }

        $this->cabinets->credit($user, $jetons);
    }

    private function boardsWonToday(GameSession $session): int
    {
        $puzzleDate = $session->getPuzzleDate();
        if (null === $puzzleDate) {
            return 0;
        }

        return (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(DISTINCT s.game)
            FROM card_game_reward r
            JOIN game_session s ON s.id = r.game_session_id
            WHERE r.user_id = :userId AND s.puzzle_date = :puzzleDate',
            [
                'userId' => (string) $session->getUser()->getId(),
                'puzzleDate' => $puzzleDate->format('Y-m-d'),
            ]
        )->fetchOne();
    }

}
