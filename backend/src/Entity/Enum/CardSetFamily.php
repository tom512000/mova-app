<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The axes along which work cards group into sets to complete.
 *
 * These mirror BadgeCategory's non-credit half deliberately, and share its SQL shapes: a
 * decade here must mean exactly what a decade means on the badge shelf, so CardSetService
 * reads the same `((release_year / 10) * 10)` expression BadgeService already uses rather
 * than writing a second one that could drift.
 *
 * Why no credit families. A "every card of every film Scorsese directed" set would be a set
 * whose membership changes when a *person* card is scored, not when a work is — and the
 * person is already a card, already collectible, already the interesting object. FRANCHISE
 * is the one that folds its own card in, because a saga's card is genuinely the capstone of
 * the films in it.
 */
enum CardSetFamily: string
{
    case DECADE = 'decade';
    case GENRE = 'genre';
    case COUNTRY = 'country';
    case STUDIO = 'studio';
    case FRANCHISE = 'franchise';

    /**
     * The smallest set worth completing.
     *
     * Five, and it is a guard rather than a taste: without it, a two-film production shell
     * that TMDB credits once is a completed set and a free bonus the moment both cards are
     * pulled. The number is shared with CardSetService's HAVING clause.
     */
    public const MINIMUM_SIZE = 5;
}
