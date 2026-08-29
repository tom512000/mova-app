<?php

declare(strict_types=1);

namespace App\DTO\Letterboxd;

/**
 * One parsed <item> from the user's Letterboxd RSS feed that represents an actual
 * diary/review entry (as opposed to a "list updated" item — see LetterboxdRssClient).
 */
final readonly class RssDiaryEntry
{
    public function __construct(
        public string $guid,
        public string $filmTitle,
        public ?int $filmYear,
        public int $tmdbMovieId,
        public string $filmSlug,
        public \DateTimeImmutable $watchedDate,
        public ?float $rating,
        public bool $isRewatch,
        public ?string $reviewText,
        /**
         * Letterboxd exposes this nowhere in the feed's own vocabulary — no element, no
         * attribute. The only trace is a "(contains spoilers)" suffix on the item's <title>,
         * which is why this is read there and why it defaults to false: an unrecognised
         * title shape must mean "no marker", never a spoiler warning nobody asked for.
         */
        public bool $containsSpoilers = false,
    ) {
    }
}
