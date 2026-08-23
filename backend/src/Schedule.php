<?php

namespace App;

use App\Message\SyncLetterboxdRssMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%app.letterboxd.rss_sync_enabled%')]
        private bool $rssSyncEnabled,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
        ;

        // Gated by LETTERBOXD_RSS_SYNC_ENABLED so a deployment without a configured
        // username doesn't hammer a feed URL that isn't meaningful.
        if ($this->rssSyncEnabled) {
            $schedule->with(RecurringMessage::every('1 hour', new SyncLetterboxdRssMessage()));
        }

        return $schedule;
    }
}
