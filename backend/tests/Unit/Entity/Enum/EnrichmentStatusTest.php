<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Enum;

use App\Entity\Enum\EnrichmentStatus;
use PHPUnit\Framework\TestCase;

/**
 * One rule, read from three places: the importer decides what to queue, the RSS sync decides
 * the same thing about its own films, and the handler decides whether to act once the message
 * arrives. They used to disagree — the RSS sync queued EXCLUDED films the handler would then
 * refuse — so the rule is pinned down here rather than in each of them.
 */
final class EnrichmentStatusTest extends TestCase
{
    public function testAFilmThatIsDoneNeedsNothing(): void
    {
        self::assertFalse(EnrichmentStatus::ENRICHED->needsEnrichment());

        // Terminal in a different way: a human confirmed there is no TMDB match, and asking
        // again is how a wrong candidate got picked on the next re-import.
        self::assertFalse(EnrichmentStatus::EXCLUDED->needsEnrichment());
    }

    public function testEveryOtherStateIsStillWorthAnAttempt(): void
    {
        self::assertTrue(EnrichmentStatus::PENDING->needsEnrichment());
        self::assertTrue(EnrichmentStatus::FAILED->needsEnrichment());
        self::assertTrue(EnrichmentStatus::AMBIGUOUS->needsEnrichment());
    }

    public function testTheListForAQueryAgreesWithThePredicate(): void
    {
        // The two exist so that a repository can put the rule in an IN clause. If they ever
        // drift apart, the filtering and the handler stop agreeing and messages start being
        // queued for films that will refuse them.
        self::assertSame(
            array_values(array_filter(
                EnrichmentStatus::cases(),
                static fn (EnrichmentStatus $status) => $status->needsEnrichment()
            )),
            EnrichmentStatus::needingEnrichment()
        );
    }

    public function testANewCaseHasToDeclareWhichSideItIsOn(): void
    {
        // Guards against a case being added and silently landing in "retry forever" without
        // anyone deciding that is what it should do.
        self::assertCount(3, EnrichmentStatus::needingEnrichment());
        self::assertCount(5, EnrichmentStatus::cases());
    }
}
