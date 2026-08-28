<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tmdb;

use App\Exception\TmdbException;
use App\Service\Tmdb\SeriesRuntimeResolver;
use App\Service\Tmdb\TmdbClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SeriesRuntimeResolverTest extends TestCase
{
    public function testItAddsUpEveryEpisodeAcrossEverySeason(): void
    {
        $client = $this->createMock(TmdbClientInterface::class);
        $client->method('getTvSeason')->willReturnMap([
            [84958, 1, $this->season([53, 56, 44, 50, 51, 48])],
            [84958, 2, $this->season([48, 52, 56, 51, 47, 59])],
        ]);

        // The real figure for Loki, which TMDB reports nowhere on /tv/84958 itself.
        self::assertSame(615, $this->resolver($client)->totalMinutes(84958, $this->details([1, 2])));
    }

    public function testSpecialsAreLeftOut(): void
    {
        $client = $this->createMock(TmdbClientInterface::class);
        $client->expects(self::once())
            ->method('getTvSeason')
            ->with(84958, 1)
            ->willReturn($this->season([50, 50]));

        // Season 0 holds specials, which TMDB also excludes from number_of_episodes —
        // counting them here would make the two disagree.
        self::assertSame(100, $this->resolver($client)->totalMinutes(84958, $this->details([0, 1])));
    }

    public function testASeriesWhoseEpisodesCarryNoRuntimeIsUnknownRatherThanZero(): void
    {
        $client = $this->createMock(TmdbClientInterface::class);
        $client->method('getTvSeason')->willReturn($this->season([0, 0]));

        // Zero would otherwise be summed into watch-time totals as though it were a fact.
        self::assertNull($this->resolver($client)->totalMinutes(84958, $this->details([1])));
    }

    public function testAnUnreachableSeasonCostsTheRuntimeAndNothingElse(): void
    {
        $client = $this->createMock(TmdbClientInterface::class);
        $client->method('getTvSeason')->willThrowException(new TmdbException('Erreur TMDB 500'));

        // A series is well worth keeping without its duration, so this never throws and
        // never reports a partial total as if it were complete.
        self::assertNull($this->resolver($client)->totalMinutes(84958, $this->details([1, 2])));
    }

    private function resolver(TmdbClientInterface $client): SeriesRuntimeResolver
    {
        return new SeriesRuntimeResolver($client, new NullLogger());
    }

    /**
     * @param list<int> $seasonNumbers
     *
     * @return array<string, mixed>
     */
    private function details(array $seasonNumbers): array
    {
        return ['seasons' => array_map(
            static fn (int $number) => ['season_number' => $number],
            $seasonNumbers
        )];
    }

    /**
     * @param list<int> $runtimes
     *
     * @return array<string, mixed>
     */
    private function season(array $runtimes): array
    {
        return ['episodes' => array_map(
            static fn (int $runtime) => ['runtime' => $runtime],
            $runtimes
        )];
    }
}
