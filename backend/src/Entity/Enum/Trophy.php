<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The trophy catalogue: a fixed, hand-written list, where badges are generated.
 *
 * The difference is the whole reason the two live apart. A badge exists for every genre,
 * country and person a library holds enough of, so there are hundreds and none of them is
 * special; a trophy is one named thing with a joke attached, and there are fourteen. Mixed
 * on one shelf, "Le Père Noël est une ordure" would sit on page twelve behind level-one
 * actors.
 *
 * Language-free like every other enum here: the names — which are film titles, and the
 * point of the whole exercise — and the one-line jokes belong with the rest of the French
 * copy in the frontend. What this carries is what the arithmetic needs: which shelf, and
 * where the rungs are.
 */
enum Trophy: string
{
    // Régularité
    case GROUNDHOG_DAY = 'groundhog_day';
    case WEEKENDS = 'weekends';
    case DIRTY_DOZEN = 'dirty_dozen';
    case RETURN_OF_THE_JEDI = 'return_of_the_jedi';
    case OLD_TIMERS = 'old_timers';

    // Dates spéciales
    case CHRISTMAS = 'christmas';
    case NEW_YEAR = 'new_year';
    case VALENTINE = 'valentine';
    case EASTER = 'easter';
    case LABOUR_DAY = 'labour_day';
    case BASTILLE_DAY = 'bastille_day';
    case HALLOWEEN = 'halloween';
    case FRIDAY_THE_13TH = 'friday_the_13th';
    case LEAP_DAY = 'leap_day';

    public function family(): TrophyFamily
    {
        return match ($this) {
            self::GROUNDHOG_DAY, self::WEEKENDS, self::DIRTY_DOZEN, self::RETURN_OF_THE_JEDI, self::OLD_TIMERS => TrophyFamily::REGULARITY,
            default => TrophyFamily::SPECIAL_DATES,
        };
    }

    /**
     * The rungs, ascending, in whatever unit the trophy counts: days in a row, weekends,
     * months, days without a film, years, or days that fell on the date. A trophy with a
     * single rung is won once and has no levels to show.
     *
     * @return list<int>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::GROUNDHOG_DAY => [3, 7, 14, 30, 60, 100],
            self::WEEKENDS => [5, 20, 50],
            self::DIRTY_DOZEN => [12],
            self::RETURN_OF_THE_JEDI => [30],
            self::OLD_TIMERS => [1, 3, 5, 10],
            // Thirteen and not ten: it is a Friday the 13th trophy. There are one to three a
            // year, so the last rung is several years of superstition away, which is fine.
            self::FRIDAY_THE_13TH => [1, 5, 13],
            default => [1],
        };
    }
}
