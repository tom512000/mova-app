<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Country;
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
 * Genre row and nothing collides. The series-only ones ("Sci-Fi & Fantasy", "War & Politics")
 * simply join the same table, which is why the genre filter can show both "Science-Fiction"
 * and "Science-Fiction & Fantastique".
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

    public function __construct(
        private readonly GenreRepository $genreRepository,
        private readonly CountryRepository $countryRepository,
        private readonly PersonRepository $personRepository,
        private readonly StudioRepository $studioRepository,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    protected function resetPersonCache(): void
    {
        $this->personCache = [];
    }

    /**
     * @param array<int, array{id: int, name: string}> $genres
     */
    protected function mapGenres(Movie $movie, array $genres): void
    {
        $movie->clearGenres();
        foreach ($genres as $genreData) {
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
                $country->setName($countryData['name']);
                $this->entityManager->persist($country);
            }
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
