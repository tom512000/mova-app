<?php

declare(strict_types=1);

namespace App\Service\Trophy;

use App\DTO\Trophy\TrophyDto;
use App\Entity\Enum\WatchSource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one query trophies need: the days this profile actually watched something.
 *
 * Everything else is TrophyCalculator's job, which never sees a database.
 */
final class TrophyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrophyCalculator $calculator,
    ) {
    }

    /**
     * @return list<TrophyDto>
     */
    public function getTrophies(User $user, ?\DateTimeImmutable $today = null): array
    {
        $days = $this->entityManager->getConnection()->executeQuery(
            // Revised ratings are not evenings. A note moved on Christmas Day would otherwise
            // hand out "Le Père Noël est une ordure" for a rating, and a re-rating dated in
            // the middle of a pause would split a comeback that really happened. Undated rows
            // say nothing about a day and are left out for the same reason.
            'SELECT DISTINCT w.watched_date
            FROM watch w
            WHERE w.user_id = :userId
                AND w.watched_date IS NOT NULL
                AND w.source <> :deducedSource
            ORDER BY w.watched_date',
            [
                'userId' => (string) $user->getId(),
                'deducedSource' => WatchSource::CSV_RERATING->value,
            ]
        )->fetchFirstColumn();

        return $this->calculator->compute(
            array_values(array_map('strval', $days)),
            $today ?? new \DateTimeImmutable('today'),
        );
    }
}
