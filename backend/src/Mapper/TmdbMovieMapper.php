<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Repository\CountryRepository;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps a raw TMDB /movie/{id}?append_to_response=credits,external_ids response
 * (see App\Service\Tmdb\TmdbClient) onto a Movie entity, upserting the related
 * Genre/Country/Person/Credit rows. Only pulls the TMDB fields this app displays.
 */
final class TmdbMovieMapper
{
    private const MAX_CAST_CREDITS = 15;

    /**
     * Reset at the start of every map() call. A single movie's credits can reference
     * the same TMDB person twice (director who's also a writer, duplicate crew entries)
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
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function map(Movie $movie, array $details): void
    {
        $this->personCache = [];

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

        if (!empty($details['release_date'])) {
            $releaseDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $details['release_date']);
            if (false !== $releaseDate) {
                $movie->setReleaseDate($releaseDate);
                $movie->setReleaseYear((int) $releaseDate->format('Y'));
            }
        }

        $this->mapGenres($movie, $details['genres'] ?? []);
        $this->mapCountries($movie, $details['production_countries'] ?? []);
        $this->mapCredits($movie, $details['credits'] ?? []);

        $movie->touch();
    }

    /**
     * @param array<int, array{id: int, name: string}> $genres
     */
    private function mapGenres(Movie $movie, array $genres): void
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
    private function mapCountries(Movie $movie, array $countries): void
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

    /**
     * @param array{id: int, name: string, profile_path?: string|null} $personData
     */
    private function findOrCreatePerson(array $personData): Person
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
        $person->setProfilePath($personData['profile_path'] ?? null);

        return $this->personCache[$tmdbId] = $person;
    }
}
