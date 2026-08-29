<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;

/**
 * Maps a raw TMDB /tv/{id}?append_to_response=aggregate_credits,external_ids response onto
 * a Movie entity. The series counterpart to TmdbMovieMapper.
 *
 * TMDB describes the same facts under different names on its two catalogues, so most of
 * this class is a translation table:
 *
 *   title           → name                  original_title → original_name
 *   release_date    → first_air_date        (plus last_air_date, which films have no use for)
 *   runtime         → nothing usable        (see $totalRuntimeMinutes below)
 *   Director credit → created_by            budget/revenue → absent entirely
 *                     (stored as CREATOR, not DIRECTOR — see below)
 *
 * The cast is the one place where the shapes genuinely diverge rather than merely being
 * renamed, and it is worth the trouble: the series-level `credits` block lists a token cast
 * (7 people on Loki) while `aggregate_credits` spans every episode (92). The price is that
 * a person's character sits in a `roles` list instead of a flat `character` field, and a
 * crew member's job in a `jobs` list instead of a flat `job`.
 */
final class TmdbSeriesMapper extends AbstractTmdbMapper
{
    private const MAX_CAST_CREDITS = 15;

    /**
     * @param array<string, mixed> $details
     * @param int|null             $totalRuntimeMinutes every episode's runtime added up, as
     *                                                  resolved by SeriesRuntimeResolver —
     *                                                  TMDB's own `episode_run_time` is empty
     *                                                  on most modern series, so it cannot be
     *                                                  read off $details
     */
    public function map(Movie $movie, array $details, ?int $totalRuntimeMinutes = null): void
    {
        $this->resetPersonCache();

        $movie->setMediaType(MediaType::SERIES);
        $movie->setTmdbId($details['id']);
        $movie->setTitle($details['name'] ?? $movie->getTitle());
        $movie->setOriginalTitle($details['original_name'] ?? null);
        $movie->setSynopsis(('' !== ($details['overview'] ?? '')) ? $details['overview'] : null);
        $movie->setTagline(('' !== ($details['tagline'] ?? '')) ? $details['tagline'] : null);
        $movie->setOriginalLanguage($details['original_language'] ?? null);
        $movie->setPopularity(isset($details['popularity']) ? (float) $details['popularity'] : null);
        $movie->setTmdbVoteAverage(isset($details['vote_average']) ? (float) $details['vote_average'] : null);
        $movie->setTmdbVoteCount($details['vote_count'] ?? null);
        $movie->setPosterPath($details['poster_path'] ?? null);
        $movie->setBackdropPath($details['backdrop_path'] ?? null);
        $movie->setImdbId($details['external_ids']['imdb_id'] ?? null);

        // TMDB tracks neither for series. Left explicitly null rather than untouched, so a
        // film wrongly matched then re-matched to a series doesn't keep the old figures.
        $movie->setBudget(null);
        $movie->setRevenue(null);

        $movie->setRuntimeMinutes($totalRuntimeMinutes);
        $movie->setSeasonCount(!empty($details['number_of_seasons']) ? (int) $details['number_of_seasons'] : null);
        $movie->setEpisodeCount(!empty($details['number_of_episodes']) ? (int) $details['number_of_episodes'] : null);

        if (null !== ($firstAired = $this->parseDate($details['first_air_date'] ?? null))) {
            $movie->setReleaseDate($firstAired);
            $movie->setReleaseYear((int) $firstAired->format('Y'));
        }
        $movie->setLastAirDate($this->parseDate($details['last_air_date'] ?? null));

        $this->mapGenres($movie, $details['genres'] ?? []);
        $this->mapCountries($movie, $details['production_countries'] ?? []);
        $this->mapStudios($movie, $details['production_companies'] ?? []);
        $this->mapCredits($movie, $details);

        $movie->touch();
    }

    /**
     * @param array<string, mixed> $details
     */
    private function mapCredits(Movie $movie, array $details): void
    {
        $movie->clearCredits();

        // A series has no director of record. created_by is the nearest thing, but it is a
        // different job and gets a role of its own: filed under DIRECTOR, as it was at first,
        // it put whoever created a series into the most-watched *directors* ranking. TMDB
        // keeps episode directors in the per-episode payload, which this app never fetches,
        // so a series simply has no DIRECTOR credits at all.
        foreach ($details['created_by'] ?? [] as $creator) {
            $movie->addCredit(new Credit($movie, $this->findOrCreatePerson($creator), CreditRole::CREATOR));
        }

        $credits = $details['aggregate_credits'] ?? [];

        $seenWriterIds = [];
        foreach ($credits['crew'] ?? [] as $crewMember) {
            if (!$this->hasJob($crewMember, ['Writer', 'Screenplay', 'Story'])) {
                continue;
            }
            if (isset($seenWriterIds[$crewMember['id']])) {
                continue;
            }
            $seenWriterIds[$crewMember['id']] = true;
            $movie->addCredit(new Credit($movie, $this->findOrCreatePerson($crewMember), CreditRole::WRITER));
        }

        $cast = \array_slice($credits['cast'] ?? [], 0, self::MAX_CAST_CREDITS);
        foreach ($cast as $castMember) {
            $credit = new Credit($movie, $this->findOrCreatePerson($castMember), CreditRole::ACTOR);
            $credit->setCharacterName($this->firstCharacterName($castMember));
            $credit->setCastOrder($castMember['order'] ?? null);
            $movie->addCredit($credit);
        }
    }

    /**
     * A crew member's jobs across the whole run, e.g. [{job: "Writer", episode_count: 6}].
     *
     * @param array<string, mixed> $crewMember
     * @param list<string>         $wanted
     */
    private function hasJob(array $crewMember, array $wanted): bool
    {
        foreach ($crewMember['jobs'] ?? [] as $job) {
            if (\in_array($job['job'] ?? '', $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The most-present role, TMDB having already ordered `roles` by episode count — an
     * actor billed under several characters (a dual role, a recast) shows the one they
     * played the longest.
     *
     * @param array<string, mixed> $castMember
     */
    private function firstCharacterName(array $castMember): ?string
    {
        $character = $castMember['roles'][0]['character'] ?? '';

        return '' !== $character ? $character : null;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date ? $date : null;
    }
}
