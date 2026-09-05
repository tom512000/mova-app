<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mapper;

use App\Entity\Enum\CreditRole;
use App\Entity\Movie;
use App\Mapper\TmdbMovieMapper;
use App\Repository\CountryRepository;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * TMDB's release_dates block, which is the only place the film's French opening day exists.
 *
 * The block is a list per country, each holding several dated events of different kinds, and
 * picking the wrong kind is not a rounding error: a festival premiere can precede the public
 * opening by months, and a digital release follows it by as many. Both would make "vu à sa
 * sortie" say something untrue.
 */
final class TmdbMovieMapperTest extends TestCase
{
    private const PREMIERE = 1;
    private const THEATRICAL_LIMITED = 2;
    private const THEATRICAL = 3;
    private const DIGITAL = 4;
    private const PHYSICAL = 5;
    private const TV = 6;

    public function testTheFrenchTheatricalDateIsRead(): void
    {
        $date = $this->mapper()->frenchTheatricalRelease([
            $this->country('US', [[self::THEATRICAL, '2025-05-17T00:00:00.000Z']]),
            $this->country('FR', [[self::THEATRICAL, '2025-05-21T00:00:00.000Z']]),
        ]);

        self::assertSame('2025-05-21', $date?->format('Y-m-d'));
    }

    public function testOnlyFranceIsLookedAt(): void
    {
        $date = $this->mapper()->frenchTheatricalRelease([
            $this->country('US', [[self::THEATRICAL, '2025-05-17T00:00:00.000Z']]),
            $this->country('BE', [[self::THEATRICAL, '2025-05-19T00:00:00.000Z']]),
        ]);

        self::assertNull($date, 'a neighbour opening the same week is still not France');
    }

    public function testTheEarliestTheatricalDateWins(): void
    {
        // A limited run ahead of the wide release is still the first day it could be seen.
        $date = $this->mapper()->frenchTheatricalRelease([
            $this->country('FR', [
                [self::THEATRICAL, '2025-05-21T00:00:00.000Z'],
                [self::THEATRICAL_LIMITED, '2025-05-14T00:00:00.000Z'],
            ]),
        ]);

        self::assertSame('2025-05-14', $date?->format('Y-m-d'));
    }

    public function testAFestivalPremiereIsNotARelease(): void
    {
        // Type 1 is Cannes, not a screening anyone could buy a ticket for. Counting it would
        // date the release months early and quietly disqualify a film seen on its real
        // opening weekend.
        $date = $this->mapper()->frenchTheatricalRelease([
            $this->country('FR', [
                [self::PREMIERE, '2025-05-14T00:00:00.000Z'],
                [self::THEATRICAL, '2025-05-21T00:00:00.000Z'],
            ]),
        ]);

        self::assertSame('2025-05-21', $date?->format('Y-m-d'));
    }

    public function testDigitalPhysicalAndTelevisionDatesAreIgnored(): void
    {
        $date = $this->mapper()->frenchTheatricalRelease([
            $this->country('FR', [
                [self::DIGITAL, '2025-09-01T00:00:00.000Z'],
                [self::PHYSICAL, '2025-10-01T00:00:00.000Z'],
                [self::TV, '2026-03-01T00:00:00.000Z'],
            ]),
        ]);

        self::assertNull($date, 'none of these is the day it opened in a cinema');
    }

    public function testAFilmThatNeverOpenedHereHasNoDate(): void
    {
        // The ordinary case for a streaming release, and the reason the statistic falls back
        // to TMDB's primary date rather than dropping the film.
        self::assertNull($this->mapper()->frenchTheatricalRelease([
            $this->country('US', [[self::THEATRICAL, '2025-05-17T00:00:00.000Z']]),
        ]));
        self::assertNull($this->mapper()->frenchTheatricalRelease([]));
    }

    public function testAMalformedEntryIsSkippedRatherThanGuessedAt(): void
    {
        $date = $this->mapper()->frenchTheatricalRelease([
            ['iso_3166_1' => 'FR', 'release_dates' => [
                ['type' => self::THEATRICAL],
                ['type' => self::THEATRICAL, 'release_date' => ''],
                ['type' => self::THEATRICAL, 'release_date' => '2025-05-21T00:00:00.000Z'],
            ]],
        ]);

        self::assertSame('2025-05-21', $date?->format('Y-m-d'));
    }

    public function testOnlyThePlainProducerJobCounts(): void
    {
        $movie = new Movie('test-producers', 'Test');

        $this->mapper()->mapProducers($movie, [
            ['id' => 1, 'name' => 'Vraie Productrice', 'job' => 'Producer'],
            ['id' => 2, 'name' => 'Financier', 'job' => 'Executive Producer'],
            ['id' => 3, 'name' => 'Adjoint', 'job' => 'Associate Producer'],
            ['id' => 4, 'name' => 'Coproducteur', 'job' => 'Co-Producer'],
            ['id' => 5, 'name' => 'Directrice de production', 'job' => 'Line Producer'],
            ['id' => 6, 'name' => 'Realisatrice', 'job' => 'Director'],
        ]);

        // One name out of six. The neighbouring Production-department jobs are left out on
        // purpose - an executive producer credit is very often a financing arrangement, and
        // counting those would fill a "most-watched producers" ranking with people who were
        // never on a set. See CreditRole::PRODUCER.
        self::assertSame(['Vraie Productrice'], $this->producerNames($movie));
    }

    public function testASeriesCrewMemberIsReadFromItsJobsArray(): void
    {
        $movie = new Movie('test-producers-series', 'Test');

        // aggregate_credits shapes a crew member differently: one row per person, carrying
        // every job they held across the run rather than a single job string.
        $this->mapper()->mapProducers($movie, [
            ['id' => 10, 'name' => 'Productrice', 'jobs' => [
                ['job' => 'Executive Producer', 'episode_count' => 8],
                ['job' => 'Producer', 'episode_count' => 3],
            ]],
            ['id' => 11, 'name' => 'Seulement Executive', 'jobs' => [
                ['job' => 'Executive Producer', 'episode_count' => 8],
            ]],
        ]);

        self::assertSame(['Productrice'], $this->producerNames($movie));
    }

    public function testTheSamePersonIsCreditedOnce(): void
    {
        $movie = new Movie('test-producers-dedup', 'Test');

        $this->mapper()->mapProducers($movie, [
            ['id' => 20, 'name' => 'Productrice', 'job' => 'Producer'],
            ['id' => 20, 'name' => 'Productrice', 'job' => 'Producer'],
        ]);

        self::assertSame(['Productrice'], $this->producerNames($movie));
    }

    public function testAnEmptyCrewCreditsNobody(): void
    {
        $movie = new Movie('test-producers-empty', 'Test');

        $this->mapper()->mapProducers($movie, []);

        self::assertSame([], $this->producerNames($movie));
    }

    /**
     * @return list<string>
     */
    private function producerNames(Movie $movie): array
    {
        $names = [];
        foreach ($movie->getCredits() as $credit) {
            if (CreditRole::PRODUCER === $credit->getRole()) {
                $names[] = $credit->getPerson()->getName();
            }
        }

        return $names;
    }

    /**
     * @param list<array{int, string}> $releases
     *
     * @return array{iso_3166_1: string, release_dates: list<array{type: int, release_date: string}>}
     */
    private function country(string $iso, array $releases): array
    {
        return [
            'iso_3166_1' => $iso,
            'release_dates' => array_map(
                static fn (array $release) => ['type' => $release[0], 'release_date' => $release[1]],
                $releases
            ),
        ];
    }

    private function mapper(): TmdbMovieMapper
    {
        return new TmdbMovieMapper(
            $this->createMock(GenreRepository::class),
            $this->createMock(CountryRepository::class),
            $this->createMock(PersonRepository::class),
            $this->createMock(StudioRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }
}
