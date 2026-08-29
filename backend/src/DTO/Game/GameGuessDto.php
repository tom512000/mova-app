<?php

declare(strict_types=1);

namespace App\DTO\Game;

final readonly class GameGuessDto
{
    /**
     * @param list<ComparisonFacetDto>|null $facets the attribute-by-attribute verdict, in
     *                                              the comparison game only; null in the
     *                                              clue game, where a guess says nothing
     *                                              beyond right or wrong
     */
    public function __construct(
        public string $movieId,
        public string $title,
        public ?int $releaseYear,
        public ?string $posterUrl,
        public bool $correct,
        public ?array $facets = null,
    ) {
    }
}
