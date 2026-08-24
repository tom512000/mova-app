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
    public function testPrefersTheLetterboxdPageOverASearchMatch(): void
    {
        $movie = $this->movie('back-to-school-2019', 'Back to School', 2019);

        // The regression this ordering exists for: TmdbClient searches with language=fr-FR,
        // so the real film ("La Grande Classe") carries neither the title nor the original
        // title Letterboxd exported, while an unrelated 1-vote short named exactly
        // "Back To School" scores a perfect title+year match and wins outright.
        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->expects(self::never())->method('searchMovie');

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->expects(self::once())
            ->method('resolve')
            ->with('back-to-school-2019')
            ->willReturn(['tmdbId' => 624060, 'tmdbTvId' => null, 'imdbId' => 'tt9426210']);

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $result = $resolver->resolve($movie);

        self::assertSame(624060, $result['tmdbId']);
        self::assertSame('tt9426210', $result['imdbId']);
    }

    public function testFallsBackToAConfidentSearchMatchWhenThePageHasNoTmdbLink(): void
    {
        $movie = $this->movie('dune-part-two', 'Dune: Part Two', 2024);

        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->method('searchMovie')->willReturn([
            ['id' => 693134, 'title' => 'Dune: Part Two', 'original_title' => 'Dune: Part Two', 'release_date' => '2024-02-27'],
            ['id' => 999999, 'title' => 'Dune', 'original_title' => 'Dune', 'release_date' => '1984-01-01'],
        ]);

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->method('resolve')->willReturn(['tmdbId' => null, 'tmdbTvId' => null, 'imdbId' => null]);

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $result = $resolver->resolve($movie);

        self::assertSame(693134, $result['tmdbId']);
    }

    public function testRefusesToSearchForAMovieWhenLetterboxdPointsAtASeries(): void
    {
        $movie = $this->movie('lupin-2021-1', 'Lupin', 2021);

        // /search/movie can never return a series, so letting it run would guarantee a
        // wrong film rather than no film.
        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->expects(self::never())->method('searchMovie');

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->method('resolve')->willReturn(['tmdbId' => null, 'tmdbTvId' => 96677, 'imdbId' => null]);

        $resolver = new TmdbResolver($tmdbClient, new TitleNormalizer(), $pageResolver);

        $this->expectException(AmbiguousMatchException::class);
        $resolver->resolve($movie);
    }

    public function testThrowsWhenNothingResolves(): void
    {
        $movie = $this->movie('some-obscure-short-film', 'Some Obscure Short Film', 2019);

        $tmdbClient = $this->createMock(TmdbClientInterface::class);
        $tmdbClient->method('searchMovie')->willReturn([]);

        $pageResolver = $this->createMock(LetterboxdFilmPageResolverInterface::class);
        $pageResolver->method('resolve')->willReturn(['tmdbId' => null, 'tmdbTvId' => null, 'imdbId' => null]);

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
