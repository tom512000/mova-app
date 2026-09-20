<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Service\Game\FilmGuessGame;

/**
 * When a Cabinet day begins and ends.
 *
 * Three rules need to agree on what "today" is — the connection grant, the cap on jetons
 * salvaged from free packs, and the reward for winning a daily board — and a fourth already
 * existed before any of them: the daily game boards turn over at Paris midnight. Two
 * different "todays" in one app is a bug that files itself the first time somebody plays at
 * half past midnight, so this reads the games' constant rather than declaring a second one.
 *
 * Paris and not UTC because "aujourd'hui" has to mean the player's today, and this is a
 * single-person app whose person is in France.
 *
 * Deliberately not an interface. The tests that matter here — a grant claimed twice in one
 * day, a streak that survives a night and breaks after two — are about the conditional
 * UPDATE that applies them, and they exercise it by moving the stored date rather than by
 * moving the clock. A fake clock would let those tests pass while the SQL was wrong.
 */
final class CabinetClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(FilmGuessGame::PUZZLE_TIMEZONE));
    }

    /** Midnight today, Paris — the value the DATE columns are compared against. */
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(FilmGuessGame::PUZZLE_TIMEZONE));
    }

    public function yesterday(): \DateTimeImmutable
    {
        return $this->today()->modify('-1 day');
    }

    /**
     * When the next grant becomes available: the start of the next Paris day, as an instant
     * the client can count down to.
     */
    public function nextDayStart(): \DateTimeImmutable
    {
        return $this->today()->modify('+1 day');
    }
}
