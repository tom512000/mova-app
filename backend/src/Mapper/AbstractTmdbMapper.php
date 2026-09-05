<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Studio;
use App\Repository\CountryRepository;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What TMDB's /movie and /tv responses have in common.
 *
 * Genres, production countries, production companies and people are described with
 * identical field shapes on both endpoints, so the upsert logic for those four is shared
 * verbatim by TmdbMovieMapper and TmdbSeriesMapper. Everything that actually differs
 * between the two catalogues — titles, dates, runtime, the shape of the cast list — lives
 * in the subclasses.
 *
 * One caveat worth keeping in mind while reading the subclasses: TMDB maintains *separate*
 * genre vocabularies for films and series. The ids that appear in both (18 Drama, 35 Comedy,
 * 80 Crime, 99 Documentary…) carry the same name on both sides, so they land on the same
 * Genre row and nothing collides. Two of the series-only ones bundle concepts the film list
 * keeps apart, and mapGenres() below splits those before they ever reach the table — see
 * TvGenreVocabulary for which, and for the ones left alone.
 */
abstract class AbstractTmdbMapper
{
    /**
     * Reset at the start of every map() call. A single work's credits can reference the
     * same TMDB person twice (a director who's also a writer, duplicate crew entries)
     * within one flush boundary, so a DB lookup alone would miss the first unflushed
     * insert and try to create a duplicate — same class of bug as MovieUpserter's cache.
     *
     * @var array<int, Person>
     */
    private array $personCache = [];

    /**
     * The one TMDB job that counts as producing - see CreditRole::PRODUCER for why the
     * neighbouring Production-department jobs are left out.
     */
    private const PRODUCER_JOB = 'Producer';

    public function __construct(
        private readonly GenreRepository $genreRepository,
        private readonly CountryRepository $countryRepository,
        private readonly PersonRepository $personRepository,
        private readonly StudioRepository $studioRepository,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Public because a caller that clears the entity manager has to say so.
     *
     * The cache holds managed Person entities, and a clear() detaches every one of them.
     * Reusing it afterwards attaches a Credit to an entity Doctrine no longer knows, which
     * fails at the next flush with a message about cascade persist that names the wrong
     * culprit. map() resets it on its own; anything driving mapProducers() in a batch has
     * to do it by hand, because only that caller knows when it cleared.
     */
    public function resetPersonCache(): void
    {
        $this->personCache = [];
    }

    /**
     * @param array<int, array{id: int, name: string}> $genres
     */
    protected function mapGenres(Movie $movie, array $genres): void
    {
        $movie->clearGenres();

        // Series genres are rewritten into the film vocabulary first, so that the two
        // catalogues stop describing the same idea under two names.
        foreach (TvGenreVocabulary::translate($genres) as $genreData) {
            $genre = $this->genreRepository->findOneByTmdbId($genreData['id']);
            if (null === $genre) {
                $genre = new Genre();
                $genre->setTmdbId($genreData['id']);
                $genre->setName($genreData['name']);
                $this->entityManager->persist($genre);
            }
            $movie->addGenre($genre);
        }
    }

    /**
     * @param array<int, array{iso_3166_1: string, name: string}> $countries
     */
    protected function mapCountries(Movie $movie, array $countries): void
    {
        $movie->clearCountries();
        foreach ($countries as $countryData) {
            $country = $this->countryRepository->findOneByIsoCode($countryData['iso_3166_1']);
            if (null === $country) {
                $country = new Country();
                $country->setIsoCode($countryData['iso_3166_1']);
                $this->entityManager->persist($country);
            }

            // Set on every pass, not only on creation: TMDB never translates this field, so
            // a row created before FrenchCountryNames existed would keep its English label
            // for good otherwise.
            $country->setName(FrenchCountryNames::of($countryData['iso_3166_1'], $countryData['name']));

            $movie->addCountry($country);
        }
    }

    /**
     * @param array<int, array{id: int, name: string}> $companies
     */
    public function mapStudios(Movie $movie, array $companies): void
    {
        $movie->clearStudios();

        // Same guard as $personCache, scoped to one call: the repository lookup below only
        // sees flushed rows, so listing a company twice would create it twice.
        $seen = [];

        foreach ($companies as $companyData) {
            if ('' === ($companyData['name'] ?? '') || isset($seen[$companyData['id']])) {
                continue;
            }
            $seen[$companyData['id']] = true;

            $studio = $this->studioRepository->findOneByTmdbId($companyData['id']);
            if (null === $studio) {
                $studio = new Studio();
                $studio->setTmdbId($companyData['id']);
                $studio->setName($companyData['name']);
                $this->entityManager->persist($studio);
            }
            $movie->addStudio($studio);
        }
    }

    /**
     * The producers in a TMDB crew list, whatever shape that list arrives in.
     *
     * A film's crew entry carries one `job` string; a series' aggregate_credits entry
     * carries a `jobs` array covering the whole run. Both are read here rather than in each
     * mapper, because the job filter is the interesting part and having it in two places is
     * how the two quietly start disagreeing.
     *
     * Deduplicated by person: somebody credited as producer on a series across two separate
     * jobs entries is one producer, not two.
     *
     * @param array<int, array<string, mixed>> $crew
     */
    public function mapProducers(Movie $movie, array $crew): void
    {
        $seen = [];

        foreach ($crew as $crewMember) {
            if (!$this->isProducer($crewMember) || isset($seen[$crewMember['id']])) {
                continue;
            }
            $seen[$crewMember['id']] = true;

            $movie->addCredit(new Credit($movie, $this->findOrCreatePerson($crewMember), CreditRole::PRODUCER));
        }
    }

    /**
     * @param array<string, mixed> $crewMember
     */
    private function isProducer(array $crewMember): bool
    {
        // A film: one job per crew row.
        if (isset($crewMember['job'])) {
            return self::PRODUCER_JOB === $crewMember['job'];
        }

        // A series: every job this person held across the run.
        foreach ($crewMember['jobs'] ?? [] as $job) {
            if (self::PRODUCER_JOB === ($job['job'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{id: int, name: string, profile_path?: string|null} $personData
     */
    protected function findOrCreatePerson(array $personData): Person
    {
        $tmdbId = $personData['id'];

        if (isset($this->personCache[$tmdbId])) {
            return $this->personCache[$tmdbId];
        }

        $person = $this->personRepository->findOneByTmdbId($tmdbId);
        if (null === $person) {
            $person = new Person();
            $person->setTmdbId($tmdbId);
            $person->setName($personData['name']);
            $this->entityManager->persist($person);
        }
        // /tv returns "" rather than null for a person with no portrait, which would
        // otherwise be stored and later built into a broken image URL.
        $person->setProfilePath(('' !== ($personData['profile_path'] ?? '')) ? $personData['profile_path'] : null);

        return $this->personCache[$tmdbId] = $person;
    }
}
