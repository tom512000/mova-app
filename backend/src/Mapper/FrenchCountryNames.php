<?php

declare(strict_types=1);

namespace App\Mapper;

/**
 * The French name of a production country, from its ISO code.
 *
 * TMDB returns production_countries in English whatever language is asked for — the genres
 * come back translated, the countries never do — so the library showed "United States of
 * America" and "Belgium" on an otherwise entirely French interface.
 *
 * ICU already knows all of this, so there is no hand-written table to keep in step with the
 * library: every country ever added is named correctly the day it arrives. What ICU gets
 * wrong, it gets wrong in two specific ways, and both are handled below.
 *
 * Static because it is a pure function of its arguments — see TvGenreVocabulary for the same
 * reasoning about injection.
 */
final class FrenchCountryNames
{
    /**
     * Where ICU must not have the last word.
     *
     * A null value means "keep whatever TMDB called it": an English label is a smaller error
     * than a confidently wrong French one.
     *
     * The historical codes are the dangerous half. ICU resolves each to its successor state —
     * SU becomes "Russie", YU and CS become "Serbie" — so a Soviet or Yugoslav co-production
     * would be quietly relabelled as a country that did not exist when the film was made.
     * The unambiguous ones get their real name; CS is left to TMDB because it is genuinely
     * two countries (Czechoslovakia in ISO 3166-3, Serbia and Montenegro in ICU) and guessing
     * between them would be the same mistake in a different direction.
     *
     * The other half is cosmetic: ICU's official forms for Hong Kong and Macao read
     * "R.A.S. chinoise de Hong Kong", which is accurate and unusable in a list of countries.
     *
     * @var array<string, string|null>
     */
    private const OVERRIDES = [
        'HK' => 'Hong Kong',
        'MO' => 'Macao',
        'SU' => 'Union soviétique',
        'YU' => 'Yougoslavie',
        'DD' => 'Allemagne de l\'Est',
        'CS' => null,
    ];

    /**
     * @param string $isoCode  ISO 3166-1 alpha-2, as TMDB sends it
     * @param string $tmdbName TMDB's own English label, used whenever ICU cannot do better
     */
    public static function of(string $isoCode, string $tmdbName): string
    {
        $code = strtoupper(trim($isoCode));

        if (\array_key_exists($code, self::OVERRIDES)) {
            return self::OVERRIDES[$code] ?? $tmdbName;
        }

        $french = \Locale::getDisplayRegion('-'.$code, 'fr');

        // ICU hands back the input when it does not recognise the region — TMDB uses a few
        // codes of its own for defunct states (XC, XG) that land here. It also answers
        // "région inconnue" for the ZZ placeholder, which is worse than useless in a list.
        if ($french === $code || '' === $french || 'ZZ' === $code) {
            return $tmdbName;
        }

        return $french;
    }
}
