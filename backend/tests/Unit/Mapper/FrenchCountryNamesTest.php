<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mapper;

use App\Mapper\FrenchCountryNames;
use PHPUnit\Framework\TestCase;

/**
 * Naming a country is mostly ICU's job, so what is worth testing is the handful of places
 * where ICU must not be trusted, and the shape of the fallback when it has no answer at all.
 */
final class FrenchCountryNamesTest extends TestCase
{
    public function testAnOrdinaryCountryComesBackInFrench(): void
    {
        self::assertSame('États-Unis', FrenchCountryNames::of('US', 'United States of America'));
        self::assertSame('Belgique', FrenchCountryNames::of('BE', 'Belgium'));
        self::assertSame('Royaume-Uni', FrenchCountryNames::of('GB', 'United Kingdom'));
    }

    public function testTmdbsOwnLabelIsIgnoredWhenIcuKnowsBetter(): void
    {
        // Two of TMDB's English labels are not even right in English. Nothing about them is
        // carried through, because the ISO code is what the name is derived from.
        self::assertSame('Guadeloupe', FrenchCountryNames::of('GP', 'Guadaloupe'));
        self::assertSame('Tchéquie', FrenchCountryNames::of('CZ', 'Czech Republic'));
    }

    public function testACountryWhoseFrenchNameIsItsEnglishOneIsUnchanged(): void
    {
        self::assertSame('France', FrenchCountryNames::of('FR', 'France'));
        self::assertSame('Canada', FrenchCountryNames::of('CA', 'Canada'));
    }

    public function testAVanishedStateIsNotRelabelledAsItsSuccessor(): void
    {
        // The dangerous case. ICU answers "Russie" for SU and "Serbie" for YU, which would
        // credit a Soviet or Yugoslav film to a country that did not exist when it was made.
        self::assertSame('Union soviétique', FrenchCountryNames::of('SU', 'Soviet Union'));
        self::assertSame('Yougoslavie', FrenchCountryNames::of('YU', 'Yugoslavia'));
        self::assertSame("Allemagne de l'Est", FrenchCountryNames::of('DD', 'East Germany'));
    }

    public function testAnAmbiguousHistoricalCodeKeepsWhateverTmdbCalledIt(): void
    {
        // CS is Czechoslovakia in ISO 3166-3 and Serbia and Montenegro to ICU. An English
        // label that says which one TMDB meant beats a French one that picks the wrong.
        self::assertSame('Czechoslovakia', FrenchCountryNames::of('CS', 'Czechoslovakia'));
    }

    public function testTheOfficialFormIsTradedForTheUsableOne(): void
    {
        // ICU is right that it is "R.A.S. chinoise de Hong Kong", and that is not a thing to
        // put in a list of production countries.
        self::assertSame('Hong Kong', FrenchCountryNames::of('HK', 'Hong Kong'));
        self::assertSame('Macao', FrenchCountryNames::of('MO', 'Macao'));
    }

    public function testACodeIcuDoesNotRecogniseFallsBackToTmdb(): void
    {
        // TMDB carries a few invented codes for defunct states. ICU hands the code straight
        // back for those, and a country called "XG" would be worse than an English name.
        self::assertSame('East Germany', FrenchCountryNames::of('XG', 'East Germany'));
        self::assertSame('Czechoslovakia', FrenchCountryNames::of('XC', 'Czechoslovakia'));
    }

    public function testThePlaceholderRegionIsNotACountry(): void
    {
        // ICU answers "région inconnue" for ZZ, which reads like a real entry in a list.
        self::assertSame('Unknown', FrenchCountryNames::of('ZZ', 'Unknown'));
    }

    public function testTheCodeIsReadHoweverItArrives(): void
    {
        self::assertSame('Japon', FrenchCountryNames::of('jp', 'Japan'));
        self::assertSame('Japon', FrenchCountryNames::of(' JP ', 'Japan'));
    }
}
