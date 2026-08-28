<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mapper;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;
use App\Mapper\TmdbSeriesMapper;
use App\Repository\CountryRepository;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * This class is a translation table between TMDB's two catalogues, so the tests are about
 * one thing: that every fact lands in the right field despite being called something else
 * on the way in. The payload below mirrors the real /tv/84958 response (Loki), trimmed.
 */
final class TmdbSeriesMapperTest extends TestCase
{
    public function testItReadsTheSeriesNamesFromTheirTvSpelling(): void
    {
        $movie = $this->map();

        // name / original_name, not title / original_title.
        self::assertSame('Loki', $movie->getTitle());
        self::assertSame('Loki (original)', $movie->getOriginalTitle());
        self::assertSame(MediaType::SERIES, $movie->getMediaType());
        self::assertTrue($movie->isSeries());
        self::assertSame(84958, $movie->getTmdbId());
        self::assertSame('tt9140554', $movie->getImdbId());
    }

    public function testTheFirstAirDateBecomesTheReleaseDateAndTheLastIsKeptApart(): void
    {
        $movie = $this->map();

        self::assertSame('2021-06-09', $movie->getReleaseDate()?->format('Y-m-d'));
        self::assertSame(2021, $movie->getReleaseYear());
        self::assertSame('2023-11-09', $movie->getLastAirDate()?->format('Y-m-d'));
    }

    public function testTheRuntimeIsTheTotalHandedInRatherThanAnythingInThePayload(): void
    {
        // TMDB leaves episode_run_time empty on most modern series — the real figure can
        // only be summed from the season endpoints, which is SeriesRuntimeResolver's job.
        $movie = $this->map(totalRuntimeMinutes: 615);

        self::assertSame(615, $movie->getRuntimeMinutes());
        self::assertSame(2, $movie->getSeasonCount());
        self::assertSame(12, $movie->getEpisodeCount());
    }

    public function testAnUnknownRuntimeStaysNullRatherThanZero(): void
    {
        self::assertNull($this->map(totalRuntimeMinutes: null)->getRuntimeMinutes());
    }

    public function testTheCreatorIsStoredAsTheDirectorCredit(): void
    {
        $movie = $this->map();

        $directors = array_values(array_filter(
            $movie->getCredits()->toArray(),
            static fn ($credit) => CreditRole::DIRECTOR === $credit->getRole()
        ));

        // A series has no director of record; created_by is the closest equivalent, and
        // filling the slot is what keeps the clue and comparison games playable on one.
        self::assertCount(1, $directors);
        self::assertSame('Michael Waldron', $directors[0]->getPerson()->getName());
    }

    public function testTheCastComesFromAggregateCreditsWithItsNestedCharacterName(): void
    {
        $movie = $this->map();

        $actors = array_values(array_filter(
            $movie->getCredits()->toArray(),
            static fn ($credit) => CreditRole::ACTOR === $credit->getRole()
        ));

        self::assertCount(2, $actors);
        self::assertSame('Tom Hiddleston', $actors[0]->getPerson()->getName());
        // roles[0].character, not a flat `character` field.
        self::assertSame('Loki Laufeyson', $actors[0]->getCharacterName());
        self::assertSame(0, $actors[0]->getCastOrder());
    }

    public function testAWriterIsFoundInsideTheNestedJobsList(): void
    {
        $movie = $this->map();

        $writers = array_values(array_filter(
            $movie->getCredits()->toArray(),
            static fn ($credit) => CreditRole::WRITER === $credit->getRole()
        ));

        self::assertCount(1, $writers);
        self::assertSame('Eric Martin', $writers[0]->getPerson()->getName());
    }

    public function testAnEmptyProfilePathIsNotStoredAsAPath(): void
    {
        $movie = $this->map();

        $actors = array_values(array_filter(
            $movie->getCredits()->toArray(),
            static fn ($credit) => CreditRole::ACTOR === $credit->getRole()
        ));

        // /tv sends "" rather than null for a person with no portrait, which would
        // otherwise be built into a broken image URL.
        self::assertNull($actors[1]->getPerson()->getProfilePath());
    }

    public function testGenresCountriesAndCompaniesUseTheSharedShapes(): void
    {
        $movie = $this->map();

        self::assertSame(['Drame', 'Science-Fiction & Fantastique'], $this->names($movie->getGenres()->toArray()));
        self::assertSame(['United States of America'], $this->names($movie->getCountries()->toArray()));
        self::assertSame(['Marvel Studios'], $this->names($movie->getStudios()->toArray()));
    }

    public function testFiguresTmdbDoesNotTrackForSeriesAreCleared(): void
    {
        $movie = new Movie('loki-2021', 'Loki');
        $movie->setBudget('200000000');
        $movie->setRevenue('900000000');

        $this->mapper()->map($movie, $this->payload(), null);

        // Left explicitly null, so a film re-matched to a series doesn't keep its figures.
        self::assertNull($movie->getBudget());
        self::assertNull($movie->getRevenue());
    }

    /**
     * @param object[] $entities
     *
     * @return list<string>
     */
    private function names(array $entities): array
    {
        return array_values(array_map(static fn ($entity) => $entity->getName(), $entities));
    }

    private function map(?int $totalRuntimeMinutes = 615): Movie
    {
        $movie = new Movie('loki-2021', 'Loki');
        $this->mapper()->map($movie, $this->payload(), $totalRuntimeMinutes);

        return $movie;
    }

    private function mapper(): TmdbSeriesMapper
    {
        // Every lookup misses, so the mapper creates each related entity — which is the
        // path worth exercising here; the upsert-on-hit half is shared with films.
        $genres = $this->createMock(GenreRepository::class);
        $genres->method('findOneByTmdbId')->willReturn(null);

        $countries = $this->createMock(CountryRepository::class);
        $countries->method('findOneByIsoCode')->willReturn(null);

        $people = $this->createMock(PersonRepository::class);
        $people->method('findOneByTmdbId')->willReturn(null);

        $studios = $this->createMock(StudioRepository::class);
        $studios->method('findOneByTmdbId')->willReturn(null);

        return new TmdbSeriesMapper(
            $genres,
            $countries,
            $people,
            $studios,
            $this->createMock(EntityManagerInterface::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 84958,
            'name' => 'Loki',
            'original_name' => 'Loki (original)',
            'overview' => 'La nouvelle série Disney+.',
            'tagline' => "L'heure de Loki est venue.",
            'original_language' => 'en',
            'popularity' => 58.8393,
            'vote_average' => 8.2,
            'vote_count' => 12651,
            'poster_path' => '/zNwEwSXojMrQapZHQx5fO8iph4R.jpg',
            'backdrop_path' => '/q3jHCb4dMfYF6ojikKuHd6LscxC.jpg',
            'first_air_date' => '2021-06-09',
            'last_air_date' => '2023-11-09',
            'number_of_seasons' => 2,
            'number_of_episodes' => 12,
            // Empty in the real response, and the reason the total is passed in instead.
            'episode_run_time' => [],
            'genres' => [
                ['id' => 18, 'name' => 'Drame'],
                ['id' => 10765, 'name' => 'Science-Fiction & Fantastique'],
            ],
            'production_countries' => [
                ['iso_3166_1' => 'US', 'name' => 'United States of America'],
            ],
            'production_companies' => [
                ['id' => 420, 'name' => 'Marvel Studios'],
            ],
            'created_by' => [
                ['id' => 2094567, 'name' => 'Michael Waldron', 'profile_path' => '/creator.jpg'],
            ],
            'external_ids' => ['imdb_id' => 'tt9140554'],
            'aggregate_credits' => [
                'cast' => [
                    [
                        'id' => 91606,
                        'name' => 'Tom Hiddleston',
                        'profile_path' => '/mclHxMm8aPlCPKptP67257F5GPo.jpg',
                        'order' => 0,
                        'roles' => [
                            ['character' => 'Loki Laufeyson', 'episode_count' => 12],
                        ],
                    ],
                    [
                        'id' => 1373737,
                        'name' => 'Sophia Di Martino',
                        'profile_path' => '',
                        'order' => 1,
                        'roles' => [
                            ['character' => 'Sylvie', 'episode_count' => 11],
                        ],
                    ],
                ],
                'crew' => [
                    [
                        'id' => 3322751,
                        'name' => 'Joe Studzinski',
                        'department' => 'Art',
                        'jobs' => [['job' => 'Conceptual Illustrator', 'episode_count' => 12]],
                    ],
                    [
                        'id' => 1892102,
                        'name' => 'Eric Martin',
                        'department' => 'Writing',
                        'jobs' => [['job' => 'Writer', 'episode_count' => 6]],
                    ],
                ],
            ],
        ];
    }
}
