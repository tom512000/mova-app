<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Game;

use App\DTO\Game\FacetPartDto;
use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\FacetMatch;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Service\Game\FilmComparisonBuilder;
use App\Service\Game\MovieCredits;
use PHPUnit\Framework\TestCase;

/**
 * The verdicts are the game: get one wrong and a player is misled rather than merely
 * unhelped. Everything here is in-memory — the builder only reads a Movie's collections.
 */
final class FilmComparisonBuilderTest extends TestCase
{
    private FilmComparisonBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FilmComparisonBuilder(new MovieCredits());
    }

    public function testAnIdenticalFilmComesBackGreenEverywhere(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob', 'Carol']);
        $guess = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob', 'Carol']);

        foreach ($this->builder->compare($guess, $answer) as $facet) {
            self::assertSame(FacetMatch::EXACT, $facet->match, $facet->label);
            self::assertNull($facet->direction, $facet->label);

            foreach ($facet->parts ?? [] as $part) {
                self::assertSame(FacetMatch::EXACT, $part->match, "{$facet->label} / {$part->value}");
            }
        }
    }

    public function testYearIsCloseWithinThreeAndPointsTheWay(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);

        $close = $this->facet($this->movie(2013, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']), $answer, 'Année');
        self::assertSame(FacetMatch::CLOSE, $close->match);
        // The answer is older than the guess, so the arrow has to send the player down.
        self::assertSame('down', $close->direction);

        $far = $this->facet($this->movie(2014, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']), $answer, 'Année');
        self::assertSame(FacetMatch::NONE, $far->match);
        self::assertSame('down', $far->direction);

        $under = $this->facet($this->movie(2008, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']), $answer, 'Année');
        self::assertSame('up', $under->direction);
    }

    public function testRuntimeIsCloseWithinAQuarterOfAnHour(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);

        self::assertSame(
            FacetMatch::CLOSE,
            $this->facet($this->movie(2010, 135, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']), $answer, 'Durée')->match
        );
        self::assertSame(
            FacetMatch::NONE,
            $this->facet($this->movie(2010, 136, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']), $answer, 'Durée')->match
        );
    }

    public function testEachGenreIsJudgedOnItsOwn(): void
    {
        $answer = $this->movie(2010, 120, ['Drame', 'Comédie'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);
        $guess = $this->movie(2010, 120, ['Drame', 'Horreur', 'Western'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);

        // The point of the split: "Drame" is shared and the other two are not, and the card
        // has to say which is which rather than colouring the three of them the same amber.
        self::assertSame(
            [['Drame', FacetMatch::EXACT], ['Horreur', FacetMatch::NONE], ['Western', FacetMatch::NONE]],
            $this->parts($guess, $answer, 'Genres')
        );
    }

    public function testTheOverallVerdictSummarisesTheValues(): void
    {
        $answer = $this->movie(2010, 120, ['Drame', 'Comédie'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);

        // Order must not decide the colour: the same two genres, listed the other way round.
        $reversed = $this->movie(2010, 120, ['Comédie', 'Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);
        self::assertSame(FacetMatch::EXACT, $this->facet($reversed, $answer, 'Genres')->match);

        $partial = $this->movie(2010, 120, ['Drame', 'Horreur'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);
        self::assertSame(FacetMatch::CLOSE, $this->facet($partial, $answer, 'Genres')->match);

        // Every one of the guess's genres is the answer's, but the answer has one more —
        // that is not a tie, and calling it EXACT would say the sets are the same.
        $subset = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);
        self::assertSame(FacetMatch::CLOSE, $this->facet($subset, $answer, 'Genres')->match);

        $none = $this->movie(2010, 120, ['Western'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);
        self::assertSame(FacetMatch::NONE, $this->facet($none, $answer, 'Genres')->match);
    }

    public function testStudiosAreJudgedOneByOneToo(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont', 'Pathé'], ['Alice'], ['Bob']);
        $guess = $this->movie(2010, 120, ['Drame'], ['France'], ['Pathé', 'StudioCanal'], ['Alice'], ['Bob']);

        self::assertSame(
            [['Pathé', FacetMatch::EXACT], ['StudioCanal', FacetMatch::NONE]],
            $this->parts($guess, $answer, 'Studios')
        );
    }

    public function testTheCastNamesTheActorsItShares(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob', 'Carol', 'Dave']);
        $guess = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Zoe', 'Carol']);

        // Naming the shared actor beats saying "somebody in there is also in the answer".
        self::assertSame(
            [['Zoe', FacetMatch::NONE], ['Carol', FacetMatch::EXACT]],
            $this->parts($guess, $answer, 'Casting')
        );
        self::assertSame(FacetMatch::CLOSE, $this->facet($guess, $answer, 'Casting')->match);

        $strangers = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Zoe', 'Yann']);
        self::assertSame(FacetMatch::NONE, $this->facet($strangers, $answer, 'Casting')->match);
    }

    public function testMissingDataOnEitherSideIsUnknownRatherThanADifference(): void
    {
        $answer = $this->movie(2010, 120, ['Drame'], ['France'], [], ['Alice'], ['Bob']);
        $guess = $this->movie(2010, null, ['Drame'], ['France'], ['Gaumont'], ['Alice'], ['Bob']);

        // TMDB knows no studio for the answer, so the tile cannot say anything either way —
        // and no single value may claim a verdict its attribute does not have.
        self::assertSame(FacetMatch::UNKNOWN, $this->facet($guess, $answer, 'Studios')->match);
        self::assertSame([['Gaumont', FacetMatch::UNKNOWN]], $this->parts($guess, $answer, 'Studios'));
        self::assertSame(FacetMatch::UNKNOWN, $this->facet($guess, $answer, 'Durée')->match);
        self::assertSame('—', $this->facet($guess, $answer, 'Durée')->value);
    }

    public function testTheFacetsShowTheGuessedFilmsOwnValuesOnly(): void
    {
        $answer = $this->movie(1999, 100, ['Horreur'], ['Japan'], ['Toho'], ['Alice'], ['Bob']);
        $guess = $this->movie(2010, 120, ['Drame'], ['France'], ['Gaumont'], ['Zoe'], ['Yann']);

        $values = array_map(
            static fn ($facet) => $facet->value,
            $this->builder->compare($guess, $answer)
        );

        // Nothing belonging to the answer may travel with the comparison.
        foreach (['1999', '100', 'Horreur', 'Japan', 'Toho', 'Alice', 'Bob'] as $secret) {
            self::assertStringNotContainsString($secret, implode(' | ', $values));
        }
    }

    /**
     * @return list<array{0: string, 1: FacetMatch}> each value of a list attribute with its
     *                                               own verdict, in the order shown
     */
    private function parts(Movie $guess, Movie $answer, string $label): array
    {
        $parts = $this->facet($guess, $answer, $label)->parts ?? [];

        return array_map(static fn (FacetPartDto $part) => [$part->value, $part->match], $parts);
    }

    private function facet(Movie $guess, Movie $answer, string $label): \App\DTO\Game\ComparisonFacetDto
    {
        foreach ($this->builder->compare($guess, $answer) as $facet) {
            if ($facet->label === $label) {
                return $facet;
            }
        }

        self::fail("Aucune facette « {$label} ».");
    }

    /**
     * @param list<string> $genres
     * @param list<string> $countries
     * @param list<string> $studios
     * @param list<string> $directors
     * @param list<string> $cast      in billing order
     */
    private function movie(
        ?int $year,
        ?int $runtime,
        array $genres,
        array $countries,
        array $studios,
        array $directors,
        array $cast,
    ): Movie {
        $movie = new Movie('slug-'.spl_object_id(new \stdClass()), 'Film');
        $movie->setReleaseYear($year);
        $movie->setRuntimeMinutes($runtime);

        foreach ($genres as $name) {
            $movie->addGenre((new Genre())->setName($name));
        }
        foreach ($countries as $name) {
            $movie->addCountry((new Country())->setIsoCode(substr($name, 0, 2))->setName($name));
        }
        foreach ($studios as $index => $name) {
            $movie->addStudio((new Studio())->setTmdbId($index + 1)->setName($name));
        }
        foreach ($directors as $name) {
            $movie->addCredit(new Credit($movie, (new Person())->setName($name), CreditRole::DIRECTOR));
        }
        foreach ($cast as $order => $name) {
            $credit = new Credit($movie, (new Person())->setName($name), CreditRole::ACTOR);
            $credit->setCastOrder($order);
            $movie->addCredit($credit);
        }

        return $movie;
    }
}
