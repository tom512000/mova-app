<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;
use App\Service\Import\LetterboxdSlugExtractor;
use App\Service\Letterboxd\LetterboxdRssClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixture is a real feed sample from https://letterboxd.com/tom51200/rss/, captured
 * verbatim — it mixes one genuine diary entry with three "list updated" items (the
 * user's own personal lists, not Letterboxd's built-in Watchlist), which is exactly
 * the shape the parser needs to filter correctly.
 */
final class LetterboxdRssClientTest extends TestCase
{
    private const REAL_FEED_SAMPLE = <<<'XML'
        <?xml version='1.0' encoding='utf-8'?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:letterboxd="https://letterboxd.com" xmlns:tmdb="https://themoviedb.org">
            <channel>
                <title>Letterboxd - tom51200</title>
                <link>https://letterboxd.com/tom51200/</link>
                <description>Letterboxd - tom51200</description>
                <atom:link rel="self" href="https://letterboxd.com/tom51200/rss/" type="application/rss+xml"/>

                <item> <title>Neuilly sa mère, sa mère !, 2018 - ★★½</title> <link>https://letterboxd.com/tom51200/film/neuilly-sa-mere-sa-mere/</link> <guid isPermaLink="false">letterboxd-review-1456065104</guid> <pubDate>Tue, 18 Aug 2026 11:08:11 +1200</pubDate> <letterboxd:watchedDate>2026-08-18</letterboxd:watchedDate> <letterboxd:rewatch>No</letterboxd:rewatch> <letterboxd:filmTitle>Neuilly sa mère, sa mère !</letterboxd:filmTitle> <letterboxd:filmYear>2018</letterboxd:filmYear> <letterboxd:memberRating>2.5</letterboxd:memberRating> <letterboxd:memberLike>No</letterboxd:memberLike> <tmdb:movieId>481762</tmdb:movieId> <description><![CDATA[ <p><img src="https://a.ltrbxd.com/resized/film-poster/4/1/2/8/4/1/412841-neuilly-sa-mere-sa-mere--0-600-0-900-crop.jpg?v=020fc95ef1"/></p> <p>Ca put sa mère, sa mère !</p> ]]></description> <dc:creator>tom51200</dc:creator> </item>

                <item> <title>Autres (Watchlist)</title> <link>https://letterboxd.com/tom51200/list/autres-watchlist/</link> <guid isPermaLink="false">letterboxd-list-85748859</guid> <pubDate>Mon, 27 Jul 2026 20:49:02 +1200</pubDate> <description><![CDATA[ <ul> <li> <a href="https://letterboxd.com/film/a-quiet-place-2018/">A Quiet Place</a> </li> </ul> <p>...plus 32 more. <a href="https://letterboxd.com/tom51200/list/autres-watchlist/">View the full list on Letterboxd</a>.</p> ]]></description> <dc:creator>tom51200</dc:creator> </item>

            </channel>
        </rss>
        XML;

    public function testParsesRealDiaryEntryAndSkipsListItems(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(self::REAL_FEED_SAMPLE));
        $client = new LetterboxdRssClient($httpClient, new LetterboxdSlugExtractor(), new NullLogger());

        $entries = $client->fetchDiaryEntries('tom51200');

        self::assertCount(1, $entries, 'the "list updated" item must be skipped');

        $entry = $entries[0];
        self::assertSame('letterboxd-review-1456065104', $entry->guid);
        self::assertSame('Neuilly sa mère, sa mère !', $entry->filmTitle);
        self::assertSame(2018, $entry->filmYear);
        self::assertSame(481762, $entry->tmdbMovieId);
        self::assertSame('neuilly-sa-mere-sa-mere', $entry->filmSlug);
        self::assertSame('2026-08-18', $entry->watchedDate->format('Y-m-d'));
        self::assertSame(2.5, $entry->rating);
        self::assertFalse($entry->isRewatch);
        self::assertSame('Ca put sa mère, sa mère !', $entry->reviewText);
        self::assertFalse($entry->containsSpoilers);
    }

    public function testTheRealFeedEntryCarriesNoSpoilerWarning(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(self::REAL_FEED_SAMPLE));
        $client = new LetterboxdRssClient($httpClient, new LetterboxdSlugExtractor(), new NullLogger());

        self::assertFalse($client->fetchDiaryEntries('tom51200')[0]->containsSpoilers);
    }

    public function testAFlaggedReviewIsReadFromTheTitleSuffix(): void
    {
        // The feed has no element and no attribute for this — the suffix on <title> is the
        // only trace of it anywhere in the document.
        $entry = $this->entryWithTitle('Neuilly sa mère, sa mère !, 2018 - ★★½ (contains spoilers)');

        self::assertTrue($entry->containsSpoilers);
        // And reading the title must not disturb anything else that comes off the item.
        self::assertSame('Neuilly sa mère, sa mère !', $entry->filmTitle);
        self::assertSame(2.5, $entry->rating);
    }

    public function testTheMarkerSurvivesACapitalisationChange(): void
    {
        self::assertTrue(
            $this->entryWithTitle('Neuilly sa mère, sa mère !, 2018 - ★★½ (Contains Spoilers)')->containsSpoilers
        );
    }

    public function testTheMarkerIsOnlyReadWhereLetterboxdPutsIt(): void
    {
        // A film whose own title happens to contain the words. Anchoring at the end is what
        // keeps this from becoming a warning nobody asked for.
        self::assertFalse(
            $this->entryWithTitle('(contains spoilers) le film, 2018 - ★★½')->containsSpoilers
        );
    }

    public function testATitleShapeNobodyRecognisesMeansNoMarker(): void
    {
        // The whole point of building this one-directional: Letterboxd can change the format
        // tomorrow and the worst outcome is a spoiler warning that stops appearing — never a
        // review wrongly hidden behind a click.
        self::assertFalse($this->entryWithTitle('')->containsSpoilers);
        self::assertFalse($this->entryWithTitle('Neuilly sa mère, sa mère ! ~ contient des révélations')->containsSpoilers);
    }

    private function entryWithTitle(string $title): RssDiaryEntry
    {
        $feed = str_replace(
            '<title>Neuilly sa mère, sa mère !, 2018 - ★★½</title>',
            '<title>'.$title.'</title>',
            self::REAL_FEED_SAMPLE
        );

        $client = new LetterboxdRssClient(
            new MockHttpClient(new MockResponse($feed)),
            new LetterboxdSlugExtractor(),
            new NullLogger()
        );

        return $client->fetchDiaryEntries('tom51200')[0];
    }

    public function testThrowsOnNonOkResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        $client = new LetterboxdRssClient($httpClient, new LetterboxdSlugExtractor(), new NullLogger());

        $this->expectException(\App\Exception\LetterboxdRssException::class);
        $client->fetchDiaryEntries('unknown-user');
    }
}
