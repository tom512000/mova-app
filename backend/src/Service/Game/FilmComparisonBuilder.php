<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\ComparisonFacetDto;
use App\DTO\Game\FacetPartDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\FacetMatch;
use App\Entity\Movie;

/**
 * Lays a guessed film next to the answer, attribute by attribute.
 *
 * Every judgement is made here rather than in the browser: telling the client "the answer
 * came out in 1998" so it could colour its own tiles would be handing over the answer.
 * What crosses the wire is the guessed film's own values plus a verdict per attribute.
 *
 * Attributes that hold a list — genres, countries, studios, names — are judged value by
 * value rather than as a block. A film sharing one genre out of three has something to say
 * about that genre, and a single verdict for the whole set throws it away.
 */
final class FilmComparisonBuilder
{
    /** Years apart that still count as a near miss. */
    private const YEAR_CLOSE = 3;

    /** Minutes apart that still count as a near miss. */
    private const RUNTIME_CLOSE = 15;

    /** How deep into the billing a shared actor still counts. */
    private const CAST_DEPTH = 5;

    public function __construct(
        private readonly MovieCredits $credits,
    ) {
    }

    /**
     * @return list<ComparisonFacetDto>
     */
    public function compare(Movie $guess, Movie $answer): array
    {
        return [
            $this->number('Année', $guess->getReleaseYear(), $answer->getReleaseYear(), self::YEAR_CLOSE),
            $this->number('Durée', $guess->getRuntimeMinutes(), $answer->getRuntimeMinutes(), self::RUNTIME_CLOSE, ' min'),
            $this->list('Genres', $this->names($guess->getGenres()->toArray()), $this->names($answer->getGenres()->toArray())),
            $this->list('Pays', $this->names($guess->getCountries()->toArray()), $this->names($answer->getCountries()->toArray())),
            $this->list('Studios', $this->names($guess->getStudios()->toArray()), $this->names($answer->getStudios()->toArray())),
            $this->list(
                'Réalisateur·rice',
                $this->credits->namesByRole($guess, CreditRole::DIRECTOR),
                $this->credits->namesByRole($answer, CreditRole::DIRECTOR)
            ),
            $this->list(
                'Casting',
                $this->credits->namesByRole($guess, CreditRole::ACTOR, self::CAST_DEPTH),
                $this->credits->namesByRole($answer, CreditRole::ACTOR, self::CAST_DEPTH)
            ),
        ];
    }

    /**
     * @param array<object> $items entities exposing getName()
     *
     * @return list<string>
     */
    private function names(array $items): array
    {
        return array_values(array_map(static fn (object $item) => $item->getName(), $items));
    }

    private function number(string $label, ?int $guess, ?int $answer, int $closeWithin, string $suffix = ''): ComparisonFacetDto
    {
        if (null === $guess || null === $answer) {
            return new ComparisonFacetDto($label, '—', FacetMatch::UNKNOWN);
        }

        $delta = $answer - $guess;

        return new ComparisonFacetDto(
            label: $label,
            value: $guess.$suffix,
            match: match (true) {
                0 === $delta => FacetMatch::EXACT,
                abs($delta) <= $closeWithin => FacetMatch::CLOSE,
                default => FacetMatch::NONE,
            },
            // Which way to move next is the most useful thing a numeric miss can say.
            direction: 0 === $delta ? null : ($delta > 0 ? 'up' : 'down'),
        );
    }

    /**
     * Each value is green when the answer carries it too, grey when it does not. The
     * attribute keeps an overall verdict on top — everything shared, some, or none — which
     * is what a screen reader announces instead of reading out seven colours.
     *
     * @param list<string> $guess
     * @param list<string> $answer
     */
    private function list(string $label, array $guess, array $answer): ComparisonFacetDto
    {
        if ([] === $guess || [] === $answer) {
            // Nothing to line up on one side or the other, so no colour can be honest.
            return new ComparisonFacetDto(
                label: $label,
                value: [] === $guess ? '—' : implode(' · ', $guess),
                match: FacetMatch::UNKNOWN,
                parts: array_map(static fn (string $value) => new FacetPartDto($value, FacetMatch::UNKNOWN), $guess),
            );
        }

        $parts = array_map(
            static fn (string $value) => new FacetPartDto(
                $value,
                \in_array($value, $answer, true) ? FacetMatch::EXACT : FacetMatch::NONE
            ),
            $guess
        );

        $shared = \count(array_filter($parts, static fn (FacetPartDto $part) => FacetMatch::EXACT === $part->match));

        return new ComparisonFacetDto(
            label: $label,
            value: implode(' · ', $guess),
            match: match (true) {
                // "All of mine are the answer's, and it has no others" — a genuine tie.
                $shared === \count($guess) && \count($guess) === \count($answer) => FacetMatch::EXACT,
                $shared > 0 => FacetMatch::CLOSE,
                default => FacetMatch::NONE,
            },
            parts: $parts,
        );
    }
}
