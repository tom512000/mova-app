<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Letterboxd;

use App\Service\Letterboxd\LetterboxdFilmPageResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The links below are the shape Letterboxd actually renders in a film page's footer.
 * A film backed by a TMDB *series* links to /tv/<id>, which shares no id space with
 * /movie/<id> — mistaking one for the other is what silently pulls in an unrelated film.
 */
final class LetterboxdFilmPageResolverTest extends TestCase
{
    public function testReadsTmdbMovieAndImdbIds(): void
    {
        $resolver = $this->resolverFor(<<<'HTML'
            <p class="text-link">
                <a href="http://www.imdb.com/title/tt0091042/maindetails" class="micro-button track-event">IMDb</a>
                <a href="https://www.themoviedb.org/movie/624060/" class="micro-button track-event">TMDb</a>
            </p>
            HTML);

        self::assertSame(
            ['tmdbId' => 624060, 'tmdbTvId' => null, 'imdbId' => 'tt0091042'],
            $resolver->resolve('back-to-school-2019')
        );
    }

    public function testReportsSeriesSeparatelyInsteadOfAsAMovieId(): void
    {
        $resolver = $this->resolverFor(<<<'HTML'
            <p class="text-link">
                <a href="https://www.themoviedb.org/tv/96677/" class="micro-button track-event">TMDb</a>
            </p>
            HTML);

        $result = $resolver->resolve('lupin-2021-1');

        self::assertNull($result['tmdbId'], 'A series id must never be handed out as a movie id.');
        self::assertSame(96677, $result['tmdbTvId']);
    }

    public function testReturnsNothingWhenThePageIsMissing(): void
    {
        $resolver = new LetterboxdFilmPageResolver(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertSame(
            ['tmdbId' => null, 'tmdbTvId' => null, 'imdbId' => null],
            $resolver->resolve('nope')
        );
    }

    public function testRetriesAfterAFailedLookupInsteadOfCachingTheMiss(): void
    {
        $html = '<a href="https://www.themoviedb.org/movie/8358/">TMDb</a>';
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 429]),
            new MockResponse($html),
            new MockResponse($html),
        ]);

        $resolver = new LetterboxdFilmPageResolver($httpClient, new ArrayAdapter(), new NullLogger());

        self::assertNull($resolver->resolve('cast-away')['tmdbId']);
        self::assertSame(8358, $resolver->resolve('cast-away')['tmdbId'], 'A throttled request must not become a permanent "no TMDB link".');

        $resolver->resolve('cast-away');
        self::assertSame(2, $httpClient->getRequestsCount(), 'The successful lookup must then be cached.');
    }

    private function resolverFor(string $html): LetterboxdFilmPageResolver
    {
        return new LetterboxdFilmPageResolver(
            new MockHttpClient(new MockResponse($html)),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }
}
