<?php

namespace App;

use App\Message\RebuildCardCatalogueMessage;
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

        // A nightly rescore of every card catalogue, whether or not anything came in.
        //
        // The import and the RSS sync already queue a rebuild when they change the library,
        // so this is the safety net rather than the mechanism: enrichment runs per film and
        // does not know who watched it, so a film whose TMDB data arrived hours after the
        // import that created it would otherwise keep a score computed from an empty row
        // until the next import.
        //
        // The offset is derived from the account id rather than left at zero. The schedule
        // is rebuilt on every worker start, so every account's job would otherwise fire in
        // the same second — which on one machine is a queue and on a busy one is a spike.
        foreach ($this->userRepository->findAll() as $user) {
            $schedule->with(
                RecurringMessage::every(
                    '1 day',
                    new RebuildCardCatalogueMessage((string) $user->getId()),
                    (new \DateTimeImmutable('today 03:00'))->modify(
                        sprintf('+%d seconds', crc32((string) $user->getId()) % 3600)
                    )
                )
            );
        }

        return $schedule;
    }
}
