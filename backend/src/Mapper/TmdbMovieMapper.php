<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;

/**
 * Maps a raw TMDB /movie/{id}?append_to_response=credits,external_ids response
 * (see App\Service\Tmdb\TmdbClient) onto a Movie entity, upserting the related
 * Genre/Country/Person/Credit rows. Only pulls the TMDB fields this app displays.
 *
 * The series counterpart is TmdbSeriesMapper; what the two share sits in
 * AbstractTmdbMapper.
 */
final class TmdbMovieMapper extends AbstractTmdbMapper
{
    private const MAX_CAST_CREDITS = 15;

    /**
     * @param array<string, mixed> $details
     */
    public function map(Movie $movie, array $details): void
    {
        $this->resetPersonCache();

        $movie->setMediaType(MediaType::MOVIE);
        $movie->setTmdbId($details['id']);
        $movie->setTitle($details['title'] ?? $movie->getTitle());
        $movie->setOriginalTitle($details['original_title'] ?? null);
        $movie->setSynopsis(('' !== ($details['overview'] ?? '')) ? $details['overview'] : null);
        $movie->setTagline(('' !== ($details['tagline'] ?? '')) ? $details['tagline'] : null);
        $movie->setOriginalLanguage($details['original_language'] ?? null);
        // TMDB sometimes reports 0 for a genuinely unknown runtime (obscure/under-documented
        // titles) rather than omitting the field — 0 minutes is never a real movie duration,
        // so treat it the same as missing data instead of letting it win "shortest film" stats.
        $movie->setRuntimeMinutes(!empty($details['runtime']) ? $details['runtime'] : null);
        $movie->setPopularity(isset($details['popularity']) ? (float) $details['popularity'] : null);
        $movie->setTmdbVoteAverage(isset($details['vote_average']) ? (float) $details['vote_average'] : null);
        $movie->setTmdbVoteCount($details['vote_count'] ?? null);
        $movie->setPosterPath($details['poster_path'] ?? null);
        $movie->setBackdropPath($details['backdrop_path'] ?? null);
        $movie->setBudget(isset($details['budget']) ? (string) $details['budget'] : null);
        $movie->setRevenue(isset($details['revenue']) ? (string) $details['revenue'] : null);
        $movie->setImdbId($details['external_ids']['imdb_id'] ?? $details['imdb_id'] ?? null);

        // A film has one release day, so the series-only span stays empty.
        $movie->setSeasonCount(null);
        $movie->setEpisodeCount(null);
        $movie->setLastAirDate(null);

        if (!empty($details['release_date'])) {
            $releaseDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $details['release_date']);
            if (false !== $releaseDate) {
                $movie->setReleaseDate($releaseDate);
                $movie->setReleaseYear((int) $releaseDate->format('Y'));
            }
        }

        $this->mapGenres($movie, $details['genres'] ?? []);
        $this->mapCountries($movie, $details['production_countries'] ?? []);
        $this->mapStudios($movie, $details['production_companies'] ?? []);
        $this->mapCredits($movie, $details['credits'] ?? []);

        $movie->touch();
    }

    /**
     * @param array{crew?: array<int, array<string, mixed>>, cast?: array<int, array<string, mixed>>} $credits
     */
    private function mapCredits(Movie $movie, array $credits): void
    {
        $movie->clearCredits();

        $directors = array_filter($credits['crew'] ?? [], static fn (array $c) => 'Director' === $c['job']);
        foreach ($directors as $crewMember) {
            $movie->addCredit(new Credit($movie, $this->findOrCreatePerson($crewMember), CreditRole::DIRECTOR));
        }

        $writers = array_filter(
            $credits['crew'] ?? [],
            static fn (array $c) => \in_array($c['job'], ['Writer', 'Screenplay', 'Story'], true)
        );
        $seenWriterIds = [];
        foreach ($writers as $crewMember) {
            if (isset($seenWriterIds[$crewMember['id']])) {
                continue;
            }
            $seenWriterIds[$crewMember['id']] = true;
            $movie->addCredit(new Credit($movie, $this->findOrCreatePerson($crewMember), CreditRole::WRITER));
        }

        $cast = \array_slice($credits['cast'] ?? [], 0, self::MAX_CAST_CREDITS);
        foreach ($cast as $castMember) {
            $credit = new Credit($movie, $this->findOrCreatePerson($castMember), CreditRole::ACTOR);
            $credit->setCharacterName(('' !== ($castMember['character'] ?? '')) ? $castMember['character'] : null);
            $credit->setCastOrder($castMember['order'] ?? null);
            $movie->addCredit($credit);
        }
    }
}
