<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum EnrichmentStatus: string
{
    case PENDING = 'pending';
    case ENRICHED = 'enriched';
    case FAILED = 'failed';
    case AMBIGUOUS = 'ambiguous';

    /**
     * Confirmed to have no matching TMDB movie (e.g. the Letterboxd entry is actually
     * a TV series). Unlike AMBIGUOUS, this is terminal: EnrichMovieMessageHandler and
     * findNeedingEnrichment() never retry it, so a later CSV re-import can't send
     * TmdbResolver hunting for a wrong match again.
     */
    case EXCLUDED = 'excluded';

    /**
     * Whether asking TMDB about a film in this state could still change anything.
     *
     * ENRICHED is done. EXCLUDED is done differently: a human confirmed the Letterboxd entry
     * has no TMDB match, and retrying it is what let TmdbResolver re-pick a wrong candidate
     * on every re-import. Everything else is worth another attempt.
     *
     * This used to be spelled out separately in the handler, in the RSS sync and nowhere at
     * all in the import path — and the three did not agree: the RSS sync skipped ENRICHED but
     * still queued EXCLUDED films, and the importer queued every film it touched.
     */
    public function needsEnrichment(): bool
    {
        return !\in_array($this, [self::ENRICHED, self::EXCLUDED], true);
    }

    /**
     * The same rule as a list, for the IN clause of a query.
     *
     * @return list<self>
     */
    public static function needingEnrichment(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status) => $status->needsEnrichment()));
    }
}
