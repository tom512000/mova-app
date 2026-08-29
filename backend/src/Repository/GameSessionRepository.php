<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\GameStatus;
use App\Entity\GameSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSession>
 */
class GameSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSession::class);
    }

    /**
     * The day's run, finished or not — the daily mode shows the result again rather than
     * handing out a second attempt.
     */
    public function findDaily(User $user, GameKind $game, \DateTimeImmutable $date): ?GameSession
    {
        return $this->findOneBy([
            'user' => $user,
            'game' => $game,
            'mode' => GameMode::DAILY,
            'puzzleDate' => $date,
        ]);
    }

    /**
     * The infinite run still open, so reloading the page resumes rather than restarts.
     */
    public function findOpenInfinite(User $user, GameKind $game): ?GameSession
    {
        return $this->findOneBy(
            ['user' => $user, 'game' => $game, 'mode' => GameMode::INFINITE, 'status' => GameStatus::IN_PROGRESS],
            ['id' => 'DESC']
        );
    }

    /**
     * Answers already used, newest first — fed back into the picker so the infinite mode
     * works through the library instead of circling a handful of films.
     *
     * @return list<string>
     */
    public function recentAnswerIds(User $user, GameKind $game, GameMode $mode, int $limit): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.movie) AS movieId')
            ->where('s.user = :user')
            ->andWhere('s.game = :game')
            ->andWhere('s.mode = :mode')
            ->setParameter('user', $user)
            ->setParameter('game', $game)
            ->setParameter('mode', $mode)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (string) $row['movieId'], $rows);
    }

    /**
     * The last infinite run whatever its state, so the result stays on screen after a
     * reload instead of the page offering a blank slate.
     */
    public function findLatestInfinite(User $user, GameKind $game): ?GameSession
    {
        return $this->findOneBy(
            ['user' => $user, 'game' => $game, 'mode' => GameMode::INFINITE],
            ['id' => 'DESC']
        );
    }
}
