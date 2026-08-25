<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\Credit;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\WatchSource;
use App\Entity\Genre;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * A small hand-picked, TMDB-shaped dataset so the dashboard and movie pages can be
 * exercised without a real Letterboxd export or a TMDB API key. Run with:
 *   php bin/console doctrine:fixtures:load --group=demo
 *
 * Watches need an owner now, so this loads onto the guest account from UserFixtures. It is
 * deliberately NOT in the 'users' group: loading it without --append purges every table,
 * which would destroy an imported library, so it stays opt-in and separate.
 */
final class AppFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    /** @var array<string, Genre> */
    private array $genres = [];

    /** @var array<string, Country> */
    private array $countries = [];

    /** @var array<string, Person> */
    private array $people = [];

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return string[]
     */
    public static function getGroups(): array
    {
        return ['demo'];
    }

    /**
     * @return array<class-string>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $owner = $this->requireUser(UserFixtures::GUEST_EMAIL);

        foreach ($this->movieData() as $data) {
            $movie = new Movie($data['slug'], $data['title']);
            $movie->setTmdbId($data['tmdbId']);
            $movie->setOriginalTitle($data['title']);
            $movie->setReleaseYear($data['year']);
            $movie->setReleaseDate(new \DateTimeImmutable("{$data['year']}-01-01"));
            $movie->setRuntimeMinutes($data['runtime']);
            $movie->setSynopsis($data['synopsis']);
            $movie->setTmdbVoteAverage($data['tmdbRating']);
            $movie->setTmdbVoteCount(50000);
            $movie->setOriginalLanguage($data['language']);
            $movie->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
            $movie->touch();

            foreach ($data['genres'] as $genreName) {
                $movie->addGenre($this->genre($manager, $genreName));
            }
            $movie->addCountry($this->country($manager, $data['country']));

            $director = $this->person($manager, $data['director']);
            $movie->addCredit(new Credit($movie, $director, CreditRole::DIRECTOR));

            foreach ($data['cast'] as $order => $actorName) {
                $actor = $this->person($manager, $actorName);
                $credit = new Credit($movie, $actor, CreditRole::ACTOR);
                $credit->setCastOrder($order);
                $movie->addCredit($credit);
            }

            $manager->persist($movie);

            foreach ($data['watches'] as $watchData) {
                $watch = new Watch($owner, $movie, WatchSource::CSV_IMPORT);
                $watch->setWatchedDate(new \DateTimeImmutable($watchData['date']));
                $watch->setRating($watchData['rating']);
                $watch->setIsRewatch($watchData['rewatch'] ?? false);
                $watch->setReviewText($watchData['review'] ?? null);

                foreach ($watchData['tags'] ?? [] as $tagName) {
                    $watch->addTag($this->tag($manager, $tagName));
                }

                $movie->addWatch($watch);
                $manager->persist($watch);
            }
        }

        $manager->flush();
    }

    private function requireUser(string $email): User
    {
        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            throw new \RuntimeException(sprintf('Compte "%s" absent : charge d\'abord le groupe "users".', $email));
        }

        return $user;
    }

    private function genre(ObjectManager $manager, string $name): Genre
    {
        if (!isset($this->genres[$name])) {
            $genre = new Genre();
            $genre->setName($name);
            $genre->setTmdbId(crc32($name) % 1_000_000);
            $manager->persist($genre);
            $this->genres[$name] = $genre;
        }

        return $this->genres[$name];
    }

    private function country(ObjectManager $manager, string $isoCode): Country
    {
        if (!isset($this->countries[$isoCode])) {
            $names = ['US' => 'États-Unis', 'GB' => 'Royaume-Uni', 'FR' => 'France', 'JP' => 'Japon', 'KR' => 'Corée du Sud'];
            $country = new Country();
            $country->setIsoCode($isoCode);
            $country->setName($names[$isoCode] ?? $isoCode);
            $manager->persist($country);
            $this->countries[$isoCode] = $country;
        }

        return $this->countries[$isoCode];
    }

    private function person(ObjectManager $manager, string $name): Person
    {
        if (!isset($this->people[$name])) {
            $person = new Person();
            $person->setName($name);
            $person->setTmdbId(crc32($name) % 1_000_000);
            $manager->persist($person);
            $this->people[$name] = $person;
        }

        return $this->people[$name];
    }

    private function tag(ObjectManager $manager, string $name): Tag
    {
        static $tags = [];
        if (!isset($tags[$name])) {
            $tag = new Tag($name);
            $manager->persist($tag);
            $tags[$name] = $tag;
        }

        return $tags[$name];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function movieData(): array
    {
        return [
            [
                'slug' => 'interstellar', 'tmdbId' => 157336, 'title' => 'Interstellar', 'year' => 2014,
                'runtime' => 169, 'tmdbRating' => 8.4, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Une équipe d'explorateurs voyage à travers un trou de ver dans l'espace.",
                'genres' => ['Science-Fiction', 'Aventure', 'Drame'], 'director' => 'Christopher Nolan',
                'cast' => ['Matthew McConaughey', 'Anne Hathaway', 'Jessica Chastain'],
                'watches' => [
                    ['date' => '2019-11-02', 'rating' => 4.5, 'tags' => ['space']],
                    ['date' => '2024-03-12', 'rating' => 5.0, 'rewatch' => true, 'review' => 'Encore mieux en rewatch.'],
                ],
            ],
            [
                'slug' => 'dune-part-two', 'tmdbId' => 693134, 'title' => 'Dune: Part Two', 'year' => 2024,
                'runtime' => 166, 'tmdbRating' => 8.2, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Paul Atréides s'unit à Chani et aux Fremen pour se venger.",
                'genres' => ['Science-Fiction', 'Aventure'], 'director' => 'Denis Villeneuve',
                'cast' => ['Timothée Chalamet', 'Zendaya', 'Rebecca Ferguson'],
                'watches' => [['date' => '2024-03-05', 'rating' => 4.5, 'tags' => ['cinema']]],
            ],
            [
                'slug' => 'parasite-2019', 'tmdbId' => 496243, 'title' => 'Parasite', 'year' => 2019,
                'runtime' => 133, 'tmdbRating' => 8.5, 'language' => 'ko', 'country' => 'KR',
                'synopsis' => "Toute la famille de Ki-taek est au chômage.",
                'genres' => ['Thriller', 'Drame', 'Comédie'], 'director' => 'Bong Joon-ho',
                'cast' => ['Song Kang-ho', 'Lee Sun-kyun'],
                'watches' => [['date' => '2020-01-15', 'rating' => 5.0, 'review' => 'Chef-d\'œuvre absolu.']],
            ],
            [
                'slug' => 'la-la-land', 'tmdbId' => 313369, 'title' => 'La La Land', 'year' => 2016,
                'runtime' => 128, 'tmdbRating' => 7.9, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Une pianiste de jazz et une actrice tombent amoureux à Los Angeles.",
                'genres' => ['Comédie', 'Drame', 'Musique'], 'director' => 'Damien Chazelle',
                'cast' => ['Ryan Gosling', 'Emma Stone'],
                'watches' => [
                    ['date' => '2017-02-20', 'rating' => 4.0],
                    ['date' => '2022-06-10', 'rating' => 3.5, 'rewatch' => true],
                ],
            ],
            [
                'slug' => 'everything-everywhere-all-at-once', 'tmdbId' => 545611, 'title' => 'Everything Everywhere All at Once', 'year' => 2022,
                'runtime' => 140, 'tmdbRating' => 8.0, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Une femme d'origine chinoise doit sauver le multivers.",
                'genres' => ['Science-Fiction', 'Comédie', 'Aventure'], 'director' => 'Daniel Kwan',
                'cast' => ['Michelle Yeoh', 'Ke Huy Quan'],
                'watches' => [['date' => '2023-01-08', 'rating' => 4.5, 'tags' => ['multivers']]],
            ],
            [
                'slug' => 'oppenheimer', 'tmdbId' => 872585, 'title' => 'Oppenheimer', 'year' => 2023,
                'runtime' => 181, 'tmdbRating' => 8.1, 'language' => 'en', 'country' => 'US',
                'synopsis' => "L'histoire du physicien J. Robert Oppenheimer.",
                'genres' => ['Drame', 'Histoire'], 'director' => 'Christopher Nolan',
                'cast' => ['Cillian Murphy', 'Emily Blunt', 'Matt Damon'],
                'watches' => [['date' => '2023-07-25', 'rating' => 4.5]],
            ],
            [
                'slug' => 'spirited-away', 'tmdbId' => 129, 'title' => 'Spirited Away', 'year' => 2001,
                'runtime' => 125, 'tmdbRating' => 8.5, 'language' => 'ja', 'country' => 'JP',
                'synopsis' => "Chihiro doit sauver ses parents transformés en cochons.",
                'genres' => ['Animation', 'Aventure', 'Fantastique'], 'director' => 'Hayao Miyazaki',
                'cast' => ['Rumi Hiiragi', 'Miyu Irino'],
                'watches' => [
                    ['date' => '2018-05-01', 'rating' => 5.0],
                    ['date' => '2021-12-24', 'rating' => 5.0, 'rewatch' => true],
                    ['date' => '2025-08-01', 'rating' => 5.0, 'rewatch' => true],
                ],
            ],
            [
                'slug' => 'the-notebook', 'tmdbId' => 11036, 'title' => 'The Notebook', 'year' => 2004,
                'runtime' => 123, 'tmdbRating' => 7.8, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Une histoire d'amour racontée à travers les décennies.",
                'genres' => ['Romance', 'Drame'], 'director' => 'Nick Cassavetes',
                'cast' => ['Ryan Gosling', 'Rachel McAdams'],
                'watches' => [['date' => '2016-02-14', 'rating' => 2.0, 'review' => 'Trop de guimauve pour moi.']],
            ],
            [
                'slug' => 'the-dark-knight', 'tmdbId' => 155, 'title' => 'The Dark Knight', 'year' => 2008,
                'runtime' => 152, 'tmdbRating' => 8.5, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Batman affronte le Joker à Gotham City.",
                'genres' => ['Action', 'Policier', 'Drame'], 'director' => 'Christopher Nolan',
                'cast' => ['Christian Bale', 'Heath Ledger'],
                'watches' => [
                    ['date' => '2015-08-01', 'rating' => 5.0],
                    ['date' => '2020-10-31', 'rating' => 5.0, 'rewatch' => true],
                    ['date' => '2026-01-01', 'rating' => 5.0, 'rewatch' => true],
                ],
            ],
            [
                'slug' => 'barbie', 'tmdbId' => 346698, 'title' => 'Barbie', 'year' => 2023,
                'runtime' => 114, 'tmdbRating' => 7.0, 'language' => 'en', 'country' => 'US',
                'synopsis' => "Barbie et Ken quittent Barbieland pour le monde réel.",
                'genres' => ['Comédie', 'Aventure', 'Fantastique'], 'director' => 'Greta Gerwig',
                'cast' => ['Margot Robbie', 'Ryan Gosling'],
                'watches' => [['date' => '2023-07-22', 'rating' => 3.5]],
            ],
        ];
    }
}
