<?php

declare(strict_types=1);

namespace App\Service\Card;

/**
 * What makes a card worth something, expressed once.
 *
 * Every number the four scoring passes use lives here as a constant, and every SQL fragment
 * they share is built here from those same constants. That is BadgeLadder's rule applied to
 * a bigger surface: a scoring weight will be moved while the game is balanced against a real
 * library, and a weight written in two places is a weight that will be moved in one of them.
 *
 * The scale is deliberately a **fame-and-prestige scale with a personal thumb on it**. A
 * film TMDB knows nothing about genuinely is a common card, and a null popularity coalescing
 * to zero is the correct reading rather than a fallback. What rescues the obscure is the
 * personal component: a film rated five and rewatched three times climbs a full band on its
 * own, and at 28 % of the total it is heavy enough to do that. That is the whole product
 * thesis — this is a collection built out of one person's library, not out of TMDB's.
 *
 * Everything here returns exact-decimal SQL. The casts to `numeric` are not decoration:
 * `score` is a DECIMAL column precisely so two rebuilds over an unchanged library produce
 * byte-identical ordering, and a double-precision intermediate would put the last bits of
 * every tie back in play.
 */
final class CardScoreWeights
{
    // ---------------------------------------------------------------- works

    /** TMDB's own reach: how widely known the work is, regardless of whether it is good. */
    public const WORK_AUDIENCE = 0.32;

    /** How well it is thought of, shrunk towards the mean — see BAYES_PRIOR. */
    public const WORK_ACCLAIM = 0.22;

    /** What *this* viewer did with it: the rating they gave, and how often they came back. */
    public const WORK_PERSONAL = 0.28;

    /** Money, sagas and age for films; age and sheer length for series. */
    public const WORK_PRESTIGE = 0.18;

    public const WORK_AUDIENCE_POPULARITY = 0.60;
    public const WORK_AUDIENCE_VOTES = 0.40;

    /**
     * The rating carries almost all of the personal signal, and the rewatch count very
     * little — which is the opposite of how this started.
     *
     * Measured against a real library: 745 viewings across 744 works. Almost nobody rewatches
     * anything, so at 0.35 the rewatch term was zero for essentially every card and simply
     * removed a tenth of the reachable score from the whole subject. A term that is nearly
     * always zero is not a signal, it is a lowered ceiling. It keeps a small weight because
     * where it does fire it means a great deal: coming back to a film three times is the
     * strongest statement this data can make.
     */
    public const WORK_PERSONAL_RATING = 0.85;
    public const WORK_PERSONAL_REWATCH = 0.15;

    public const FILM_PRESTIGE_REVENUE = 0.45;
    public const FILM_PRESTIGE_FRANCHISE = 0.30;
    public const FILM_PRESTIGE_AGE = 0.25;

    /**
     * Series carry neither revenue nor a franchise — TMDB models both on films only — so a
     * shared prestige formula would score every series at zero on 18 % of the total. Age and
     * total runtime substitute, and the second is more apt than it looks: a hundred-hour
     * series is a monument in a way a hundred-minute film is not.
     */
    public const SERIES_PRESTIGE_AGE = 0.50;
    public const SERIES_PRESTIGE_RUNTIME = 0.50;

    // -------------------------------------------------------------- people

    public const PERSON_REACH = 0.30;
    public const PERSON_BILLING = 0.25;
    public const PERSON_QUALITY = 0.30;
    public const PERSON_VIEWS = 0.15;

    // ------------------------------------------------- studios and sagas

    public const STUDIO_REACH = 0.40;
    public const STUDIO_QUALITY = 0.35;
    public const STUDIO_REVENUE = 0.25;

    public const FRANCHISE_REACH = 0.35;
    public const FRANCHISE_QUALITY = 0.35;

    /**
     * How much of the saga the library holds.
     *
     * franchise_film carries *every* film of a saga whether it was watched or not, which is
     * the only place in this schema that knows what is missing. It buys the one signal no
     * other source here could express: finishing a saga makes its card rarer.
     */
    public const FRANCHISE_COVERAGE = 0.15;

    public const FRANCHISE_REVENUE = 0.15;

    // ------------------------------------------------------------ ceilings

    /**
     * Where each log curve flattens. A value at its ceiling scores 1; everything below is
     * spread across the curve, which is the point of scaling by a logarithm at all —
     * popularity and revenue are distributed over orders of magnitude, and a linear scale
     * would put the whole library in the bottom percent and Avatar alone at the top.
     */
    public const CEILING_POPULARITY = 250.0;
    public const CEILING_VOTE_COUNT = 20000.0;
    public const CEILING_SERIES_RUNTIME = 6000.0;
    public const CEILING_FILM_REVENUE = 1000000000.0;
    public const CEILING_PERSON_WORKS = 40.0;

    /**
     * Viewings, not works — but in a library that barely rewatches anything the two are
     * nearly the same number, so this ceiling has to sit near the works one or the term is
     * permanently floored and stops separating anybody. 60 against a measured maximum of
     * around 40 leaves it room to mean something without letting it saturate.
     */
    public const CEILING_PERSON_VIEWS = 60.0;

    /**
     * Studio ceilings are high because studios are the subject most at risk of saturating.
     *
     * Measured on a real 744-work library: the majors carry 26 to 50 works each and sum
     * revenues well past ten billion, so at the ceilings this started with (25 works, five
     * billion) Marvel, Warner, Columbia, Fox and Paramount all pinned both terms at 1.0 and
     * became indistinguishable — eleven studios inside four points of each other, which made
     * their band assignment very nearly a coin toss. Raised, they spread out again.
     */
    public const CEILING_STUDIO_WORKS = 60.0;
    public const CEILING_STUDIO_REVENUE = 40000000000.0;

    /** Same correction, smaller: a saga of eight films pinned the old ceiling exactly. */
    public const CEILING_FRANCHISE_WORKS = 12.0;
    public const CEILING_FRANCHISE_REVENUE = 6000000000.0;

    // -------------------------------------------------------------- tuning

    /**
     * TMDB's global mean vote for titles carrying any votes at all, and how many votes it
     * takes to move away from it.
     *
     * 250 is deliberately heavy. A film with twelve votes at 9.5 comes out at
     * (12×9.5 + 250×6.4)/262 = 6.54 — essentially the prior, which is the entire point:
     * twelve enthusiasts do not make a masterpiece, and without shrinkage every obscure
     * title with a handful of adoring votes would outrank Chinatown.
     */
    public const BAYES_PRIOR = 6.4;
    public const BAYES_CONFIDENCE = 250.0;

    /**
     * The window the shrunk average is stretched over.
     *
     * Real TMDB averages live between about 5 and 9; mapping (x − 5) / 4 spreads that across
     * the full 0–1 instead of squashing every title into the middle third of a 0–10 scale.
     */
    public const ACCLAIM_FLOOR = 5.0;
    public const ACCLAIM_SPAN = 4.0;

    /**
     * What a watched-but-unrated work is worth on the rating axis.
     *
     * 0.40, which is roughly where 3.0/5 lands. Not zero: a film watched and never rated is
     * not a film that was disliked, and on a real export most of the library is unrated.
     */
    public const UNRATED_RATING_SCORE = 0.40;

    /** Rewatches stop adding after the third viewing. A fourth says nothing the third did not. */
    public const REWATCH_SATURATION = 3.0;

    /**
     * Age pays nothing for the first fifteen years and saturates at seventy-five.
     *
     * Classics are rarer than new releases, but "classic" has to mean something — last
     * year's film gets no credit for having been released.
     */
    public const AGE_GRACE_YEARS = 15;
    public const AGE_SPAN_YEARS = 60.0;

    /**
     * How fast billing decays down the cast list, and what counts as a headline credit.
     *
     * 1/(1 + order/4) rather than the classic 1/(order + 1). Cast is capped at fifteen per
     * work by the TMDB mappers, and the classic form collapses everything past third billing
     * into indistinguishable noise (0.25, 0.20, 0.17, …). The rescaled form runs
     * 1.00 → 0.67 → 0.57 → 0.50 → … → 0.22 and actually separates a lead from a supporting
     * part from a walk-on. HEADLINE_BILLING = 0.60 is exactly cast_order <= 2.
     */
    public const BILLING_DECAY = 4.0;
    public const HEADLINE_BILLING = 0.60;

    /** An uncredited cast_order is read as the bottom of a full cast rather than as the top. */
    public const UNKNOWN_CAST_ORDER = 14;

    /**
     * How much of a library a person must touch to be worth a card.
     *
     * Two works, or one where they were billed in the first three or ran the production.
     * Without a floor, a 744-work library mints something like six thousand people, four
     * fifths of them one-line parts, and the catalogue stops being a collection and becomes
     * a phone book. This is the single most consequential dial in the feature: it sets the
     * catalogue size, which sets every band size, which sets how many Légendaires exist.
     */
    public const PERSON_MINIMUM_WORKS = 2;

    /** A studio seen twice is a studio; a studio seen once is a logo on a corrected import. */
    public const STUDIO_MINIMUM_WORKS = 2;

    /**
     * SQL: scale a value onto 0–1 by a logarithm, flattening at $ceiling.
     *
     * Interpolation is safe and deliberate, the same way BadgeLadder::thresholdExpression()
     * says it is: what reaches the string is a float constant declared in this file and a
     * column expression chosen by this file's own callers. No caller-supplied value ever
     * gets here — every one of those is bound.
     */
    public static function logScale(string $expression, float $ceiling): string
    {
        return sprintf(
            'LEAST(1, LN(GREATEST(COALESCE(%s, 0)::numeric, 0) + 1) / LN(%s::numeric + 1))',
            $expression,
            self::number($ceiling)
        );
    }

    /**
     * SQL: a vote average shrunk towards the global prior by its own vote count, then
     * stretched onto 0–1. See BAYES_PRIOR for why the confidence is as heavy as it is.
     */
    public static function acclaim(string $averageColumn, string $countColumn): string
    {
        $bayes = sprintf(
            '((COALESCE(%1$s, 0)::numeric * COALESCE(%2$s::numeric, %3$s) + %4$s * %3$s) / (COALESCE(%1$s, 0)::numeric + %4$s))',
            $countColumn,
            $averageColumn,
            self::number(self::BAYES_PRIOR),
            self::number(self::BAYES_CONFIDENCE)
        );

        return sprintf(
            'GREATEST(0, LEAST(1, (%s - %s) / %s))',
            $bayes,
            self::number(self::ACCLAIM_FLOOR),
            self::number(self::ACCLAIM_SPAN)
        );
    }

    /**
     * SQL: how old a work is, on 0–1, with the grace period and saturation above.
     *
     * The current year arrives as a bound parameter rather than as PHP's idea of "now"
     * baked into the string, so a rebuild running either side of midnight on 31 December
     * cannot produce two different scales.
     */
    public static function age(string $yearColumn, string $currentYearParameter): string
    {
        return sprintf(
            'LEAST(1, GREATEST(0, (%1$s - COALESCE(%2$s, %1$s) - %3$d) / %4$s))',
            $currentYearParameter,
            $yearColumn,
            self::AGE_GRACE_YEARS,
            self::number(self::AGE_SPAN_YEARS)
        );
    }

    /**
     * SQL: how prominent a credit is, on 0–1. Direction and creation top out; everything
     * else decays down the cast list. See BILLING_DECAY.
     */
    public static function billing(string $roleColumn, string $castOrderColumn): string
    {
        return sprintf(
            "CASE %1\$s
                WHEN 'director' THEN 1.0
                WHEN 'creator' THEN 1.0
                WHEN 'writer' THEN 0.70
                WHEN 'producer' THEN 0.50
                ELSE 1.0 / (1 + COALESCE(%2\$s, %3\$d)::numeric / %4\$s)
            END",
            $roleColumn,
            $castOrderColumn,
            self::UNKNOWN_CAST_ORDER,
            self::number(self::BILLING_DECAY)
        );
    }

    /**
     * A float as a SQL numeric literal.
     *
     * %F and not %f: the lowercase one is locale-aware and would emit "0,320000" under a
     * French locale, which Postgres reads as two arguments rather than one number.
     */
    public static function number(float $value): string
    {
        return sprintf('%F', $value);
    }
}
