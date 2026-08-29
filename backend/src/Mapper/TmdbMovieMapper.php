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

    /** TMDB release types. The two that mean "in a cinema, open to the public". */
    private const THEATRICAL_LIMITED = 2;
    private const THEATRICAL = 3;

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

        $movie->setFrenchReleaseDate($this->frenchTheatricalRelease($details['release_dates']['results'] ?? []));

        $this->mapGenres($movie, $details['genres'] ?? []);
        $this->mapCountries($movie, $details['production_countries'] ?? []);
        $this->mapStudios($movie, $details['production_companies'] ?? []);
        $this->mapCredits($movie, $details['credits'] ?? []);

        $movie->touch();
    }

    /**
     * The day the film opened in French cinemas, out of TMDB's per-country release list.
     *
     * Types 3 and 2 are the theatrical ones — wide and limited. The others are a different
     * event entirely and must not be mistaken for a release: 1 is a festival premiere, which
     * a member of the public could not attend, and 4/5/6 are digital, physical and TV, which
     * come months later and would make a film look seen impossibly late.
     *
     * The earliest of the theatrical dates wins, because a limited run that precedes the wide
     * one is still the first day the film could be seen here.
     *
     * Null whenever France has no theatrical entry at all, which is the ordinary case for a
     * film released straight to streaming — the caller falls back to the primary date rather
     * than dropping the film.
     *
     * Public for the same reason mapStudios is: app:tmdb:backfill-french-releases reads the
     * same TMDB payload for the films enriched before this column existed, and must arrive at
     * the date by the identical rule rather than a second implementation of it.
     *
     * @param array<int, array{iso_3166_1?: string, release_dates?: array<int, array{type?: int, release_date?: string}>}> $countries
     */
    public function frenchTheatricalRelease(array $countries): ?\DateTimeImmutable
    {
        $earliest = null;

        foreach ($countries as $country) {
            if ('FR' !== ($country['iso_3166_1'] ?? null)) {
                continue;
            }

            foreach ($country['release_dates'] ?? [] as $release) {
                if (!\in_array($release['type'] ?? null, [self::THEATRICAL, self::THEATRICAL_LIMITED], true)) {
                    continue;
                }

                // "2025-05-21T00:00:00.000Z" — only the day matters, and the time is
                // always midnight UTC rather than anything about a screening.
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($release['release_date'] ?? ''), 0, 10));
                if (false !== $date && (null === $earliest || $date < $earliest)) {
                    $earliest = $date;
                }
            }
        }

        return $earliest;
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
