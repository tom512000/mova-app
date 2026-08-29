<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mapper;

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
