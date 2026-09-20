<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a card is a card *of*.
 *
 * Four kinds, and the split is not cosmetic: each one is scored by a different query over a
 * different join, because the signals available differ. A work carries TMDB's own numbers —
 * popularity, votes, revenue — plus the viewer's rating and rewatches. The other three carry
 * none of that and are scored purely on their footprint in this library: how many works they
 * reach, how prominently, and how good those works scored.
 *
 * STUDIO is the one that shapes the frontend. Studio holds a tmdbId and a name and nothing
 * else — no logo was ever imported — so a studio card has no artwork by construction, not by
 * accident. Rather than special-case it, the card face draws a typographic plate whenever
 * there is no image, which is also what a person without a profile photo gets.
 */
enum CardSubject: string
{
    /** A film or a series the account has watched. The only subject with TMDB metrics of its own. */
    case WORK = 'work';

    /** Anyone reachable through a credit on a watched work, in any of CreditRole's five jobs. */
    case PERSON = 'person';

    /** A production company. Never has an image — see the class docblock. */
    case STUDIO = 'studio';

    /** A saga. Films only: Franchise is not populated for series, by TMDB's own model. */
    case FRANCHISE = 'franchise';

    /**
     * The `card` column holding this subject's foreign key.
     *
     * Four real FK columns rather than one polymorphic id, so a film removed by a corrected
     * import takes its card with it instead of leaving a row pointing at nothing. This is
     * also the ON CONFLICT target the catalogue rebuild upserts against, which is why the
     * name is worth having in one place rather than spelled out at each call site.
     */
    public function foreignKeyColumn(): string
    {
        return match ($this) {
            self::WORK => 'movie_id',
            self::PERSON => 'person_id',
            self::STUDIO => 'studio_id',
            self::FRANCHISE => 'franchise_id',
        };
    }
}
