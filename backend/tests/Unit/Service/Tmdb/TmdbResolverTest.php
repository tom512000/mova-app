<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tmdb;

use App\Entity\Movie;
use App\Exception\AmbiguousMatchException;
use App\Service\Letterboxd\LetterboxdFilmPageResolverInterface;
use App\Service\Tmdb\TitleNormalizer;
use App\Service\Tmdb\TmdbClientInterface;
use App\Service\Tmdb\TmdbResolver;
use PHPUnit\Framework\TestCase;

final class TmdbResolverTest extends TestCase
{
    public function testReturnsConfidentSearchMatch(): void
    {
        $movie = $this->movie('dune-part-two', 'Dune: Part Two', 2024);

        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->method('searchMovie')->willReturn([
            ['id' => 693134, 'title' => 'Dune: Part Two', 'original_title' => 'Dune: Part Two', 'release_date' => '2024-02-27'],
            ['id' => 999999, 'title' => 'Dune', 'original_title' => 'Dune', 'release_date' => '1984-01-01'],
        ]);

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->expects(self::never())->method('resolve');

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $result = $resolver->resolve($movie);

        self::assertSame(693134, $result['tmdbId']);
    }

    public function testFallsBackToLetterboxdPageWhenSearchIsAmbiguous(): void
    {
        $movie = $this->movie('the-invisible-man', 'The Invisible Man', 2020);

        // Both candidates are one year off from the Letterboxd year (2020) and share the
        // exact same title, so they tie on score — not confident enough to pick either.
        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->method('searchMovie')->willReturn([
            ['id' => 1, 'title' => 'The Invisible Man', 'original_title' => 'The Invisible Man', 'release_date' => '2019-12-31'],
            ['id' => 2, 'title' => 'The Invisible Man', 'original_title' => 'The Invisible Man', 'release_date' => '2021-01-01'],
        ]);

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->expects(self::once())
            ->method('resolve')
            ->with('the-invisible-man')
            ->willReturn(['tmdbId' => 2, 'imdbId' => 'tt1051906']);

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $result = $resolver->resolve($movie);

        self::assertSame(2, $result['tmdbId']);
        self::assertSame('tt1051906', $result['imdbId']);
    }

    public function testThrowsWhenNothingResolves(): void
    {
        $movie = $this->movie('some-obscure-short-film', 'Some Obscure Short Film', 2019);

        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->method('searchMovie')->willReturn([]);

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->method('resolve')->willReturn(['tmdbId' => null, 'imdbId' => null]);

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $this->expectException(AmbiguousMatchException::class);
        $resolver->resolve($movie);
    }

    private function movie(string $slug, string $title, int $year): Movie
    {
        $movie = new Movie($slug, $title);
        $movie->setReleaseYear($year);

        return $movie;
    }
}
