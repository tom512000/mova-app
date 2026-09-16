<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a badge can be earned on.
 *
 * Language-free, like every other enum here: a badge's wording — "Tu as vu 15 comédies" —
 * belongs with the rest of the French copy in the frontend. What this carries is the shape
 * of the thing counted, which is the part the SQL needs.
 *
 * The five credit categories deliberately mirror CreditRole one for one rather than folding
 * into a single "personne" category. Somebody who acts in twenty films and directs five has
 * earned two different things, and one merged badge would say neither.
 */
enum BadgeCategory: string
{
    case GENRE = 'genre';
    case COUNTRY = 'country';
    case DECADE = 'decade';

    /** The budget brackets, "petit film" at one end and blockbuster at the other. */
    case BUDGET = 'budget';

    case STUDIO = 'studio';
    case DIRECTOR = 'director';
    case CREATOR = 'creator';
    case WRITER = 'writer';
    case ACTOR = 'actor';
    case PRODUCER = 'producer';

    /** The credit role this category counts, for the five that count credits. */
    public function creditRole(): ?CreditRole
    {
        return CreditRole::tryFrom($this->value);
    }
}
