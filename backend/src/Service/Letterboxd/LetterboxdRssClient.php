<?php

declare(strict_types=1);

namespace App\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;
use App\Exception\LetterboxdRssException;
use App\Service\Import\LetterboxdSlugExtractor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and parses https://letterboxd.com/{username}/rss/. Verified against a real
 * feed: it mixes actual diary/review <item>s (which carry letterboxd:filmTitle,
 * letterboxd:watchedDate and tmdb:movieId) with "list updated" <item>s (a user's own
 * lists, e.g. a personal "X (Watchlist)" list — not Letterboxd's built-in Watchlist,
 * which isn't exposed over RSS at all). Only the former are parsed; anything else is
 * skipped rather than guessed at.
 *
 * The feed only ever contains the ~50 most recent activities, so this is a
 * "catch up since last sync" mechanism, not a full-history source — the CSV export
 * remains the source of truth for backfilling a longer gap.
 *
 * Confirmed against real usage: the feed only ever includes films actually logged to
 * the Letterboxd diary (with a watched date) — a quick star rating given from a film's
 * page or a list, without going through "Log this film", never appears here at all,
 * no matter how long you wait. There is no public feed for that; those ratings only
 * ever show up in ratings.csv, so they still require a CSV re-import to sync.
 */
final class LetterboxdRssClient implements LetterboxdRssClientInterface
{
    private const LETTERBOXD_NS = 'https://letterboxd.com';
    private const TMDB_NS = 'https://themoviedb.org';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LetterboxdSlugExtractor $slugExtractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchDiaryEntries(string $username): array
    {
        $url = sprintf('https://letterboxd.com/%s/rss/', $username);

        try {
            $response = $this->httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                throw new LetterboxdRssException(sprintf('Le flux RSS Letterboxd a répondu %d pour "%s".', $statusCode, $username));
            }
            $xmlContent = $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            throw new LetterboxdRssException('Impossible de récupérer le flux RSS Letterboxd : '.$e->getMessage(), previous: $e);
        }

        $previousSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_use_internal_errors($previousSetting);

        if (false === $xml || !isset($xml->channel->item)) {
            throw new LetterboxdRssException('Flux RSS Letterboxd illisible ou vide.');
        }

        $entries = [];
        foreach ($xml->channel->item as $item) {
            $entry = $this->parseItem($item);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseItem(\SimpleXMLElement $item): ?RssDiaryEntry
    {
        $lb = $item->children(self::LETTERBOXD_NS);
        $tmdb = $item->children(self::TMDB_NS);

        // Diary/review entries only — a "list updated" item (or any future item type)
        // has none of these namespaced fields and is silently skipped, not an error.
        if (!isset($lb->filmTitle) || !isset($lb->watchedDate) || !isset($tmdb->movieId)) {
            return null;
        }

        $slug = $this->slugExtractor->extract((string) $item->link);
        if (null === $slug) {
            $this->logger->warning('Letterboxd RSS entry with no extractable film slug, skipped: {link}', ['link' => (string) $item->link]);

            return null;
        }

        $watchedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $lb->watchedDate);
        if (false === $watchedDate) {
            return null;
        }

        return new RssDiaryEntry(
            guid: (string) $item->guid,
            filmTitle: (string) $lb->filmTitle,
            filmYear: isset($lb->filmYear) && '' !== (string) $lb->filmYear ? (int) $lb->filmYear : null,
            tmdbMovieId: (int) $tmdb->movieId,
            filmSlug: $slug,
            watchedDate: $watchedDate,
            rating: isset($lb->memberRating) && '' !== (string) $lb->memberRating ? (float) $lb->memberRating : null,
            isRewatch: isset($lb->rewatch) && 'Yes' === (string) $lb->rewatch,
            reviewText: $this->extractReviewText((string) $item->description),
        );
    }

    /**
     * The description is a poster <img> optionally followed by <p> review paragraphs
     * (confirmed against a real feed entry). Strip the image, then whatever text
     * remains (if any) is the review.
     */
    private function extractReviewText(string $descriptionHtml): ?string
    {
        $withoutImage = preg_replace('#<img\b[^>]*>#i', '', $descriptionHtml) ?? '';
        $text = trim(strip_tags($withoutImage));

        return '' !== $text ? html_entity_decode($text, ENT_QUOTES | ENT_HTML5) : null;
    }
}
