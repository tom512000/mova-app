<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\ClueDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Movie;

/**
 * Turns the answer into the ladder of hints the player climbs, one rung per wrong guess.
 *
 * The order is the whole difficulty setting, and it follows the one spotle.movie settled on:
 * genre, year, country, studio, director, then the cast. Each rung narrows the field harder
 * than the one before, which is why the cast is split with the *supporting* names first —
 * three lesser-known actors say far less than the one face on the poster, so the lead is the
 * last card on the table.
 */
final class FilmClueBuilder
{
    /** How many names the supporting-cast rung gives away. */
    private const SUPPORTING_CAST = 3;

    /**
     * TMDB routinely lists half a dozen co-producers; past the first few they are financing
     * vehicles nobody recognises, and a wall of them reads as noise rather than a clue.
     */
    private const MAX_STUDIOS = 3;

    public function __construct(
        private readonly MovieCredits $credits,
    ) {
    }

    /**
     * @return list<ClueDto> in reveal order, minus any the film cannot answer
     */
    public function build(Movie $movie): array
    {
        $cast = $this->credits->namesByRole($movie, CreditRole::ACTOR);

        $candidates = [
            new ClueDto('Genres', $this->join($this->names($movie->getGenres()->toArray()))),
            new ClueDto('Année de sortie', (string) ($movie->getReleaseYear() ?? '')),
            new ClueDto('Pays de production', $this->join($this->names($movie->getCountries()->toArray()))),
            new ClueDto('Studios', $this->join(\array_slice($this->names($movie->getStudios()->toArray()), 0, self::MAX_STUDIOS))),
            new ClueDto('Réalisateur·rice', $this->join($this->credits->namesByRole($movie, CreditRole::DIRECTOR), ', ')),
            new ClueDto('Acteur·rice·s secondaires', $this->join(\array_slice($cast, 1, self::SUPPORTING_CAST), ', ')),
            new ClueDto('Acteur·rice principal·e', $this->join(\array_slice($cast, 0, 1), ', ')),
        ];

        // A film missing one of these would otherwise burn a guess on a blank card. Only
        // the studios are genuinely optional — TMDB leaves them out on some titles, and
        // nothing else in the app needed them before this game did.
        return array_values(array_filter($candidates, static fn (ClueDto $clue) => '' !== $clue->value));
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

    /**
     * @param list<string> $values
     */
    private function join(array $values, string $separator = ' · '): string
    {
        return implode($separator, $values);
    }
}
