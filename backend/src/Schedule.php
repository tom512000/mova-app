<?php

namespace App;

use App\Message\SyncLetterboxdRssMessage;
use App\Repository\UserRepository;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private UserRepository $userRepository,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
        ;

        // One recurring message per opted-in account rather than one global job: the sync
        // is per-user now, and a single message could only ever name one of them. Users
        // without a Letterboxd username are excluded by the query, so no job is scheduled
        // for a feed URL that isn't meaningful.
        //
        // The schedule is built once per worker start, so an account that enables syncing
        // later is picked up on the worker's next restart (see backend-worker's
        // --time-limit=3600 in docker-compose.yml, which recycles it hourly).
        foreach ($this->userRepository->findWithRssSyncEnabled() as $user) {
            $schedule->with(
                RecurringMessage::every('1 hour', new SyncLetterboxdRssMessage((string) $user->getId()))
            );
        }

        return $schedule;
    }
}
