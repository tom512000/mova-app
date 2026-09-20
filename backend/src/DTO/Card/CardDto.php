<?php

declare(strict_types=1);

namespace App\DTO\Card;

use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CardSubject;

/**
 * One card, as every surface that shows one needs it.
 *
 * Two rarities travel together and the client shows the first. `rarity` is what was pulled
 * and is frozen for good; `liveRarity` is what the card would be worth in today's catalogue.
 * They differ when the library has grown since — and rather than hide that, the face prints
 * a marker saying so. A collection that quietly downgraded what somebody already owned would
 * be worse than one that admits the catalogue moved.
 *
 * `percentile` is within the card's own subject, which is the only reading that makes
 * "top 0,4 %" mean anything: a film is in the top 0.4 % of that library's films, not of a
 * pile that is three quarters actors.
 */
final readonly class CardDto
{
    public function __construct(
        public string $id,
        public CardSubject $subject,
        public string $label,
        /** Null for every studio, and for anyone TMDB has no photo of. The face then sets type. */
        public ?string $imageUrl,
        /** Works only. */
        public ?int $releaseYear,
        /** 1 for a work; how many watched works the subject reaches, otherwise. */
        public int $workCount,
        /** What the card is: frozen at the pull, or the live tier while nobody owns it. */
        public CardRarity $rarity,
        /** What it would be worth in today's catalogue. Equal to $rarity until the library moves. */
        public CardRarity $liveRarity,
        /** 0 to 1 within the subject, 0 being the best. */
        public float $percentile,
        public int $copies,
        public bool $owned,
        /** False once the subject left the library: a relic, still owned, no longer drawable. */
        public bool $inCatalogue,
        /** 1 to 6, or null. */
        public ?int $showcasePosition,
    ) {
    }
}
