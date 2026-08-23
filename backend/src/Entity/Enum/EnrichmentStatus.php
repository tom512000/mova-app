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
}
