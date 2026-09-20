<?php

declare(strict_types=1);

namespace App\DTO\Card;

use App\Entity\Enum\CardPackKind;

/**
 * A whole pack opening, in one response.
 *
 * The client animates data it already holds rather than asking for each card as it turns,
 * which is what makes the reveal survive a bad connection. The same shape comes back from
 * the recent-openings endpoint, so a pack lost mid-animation can be replayed exactly.
 */
final readonly class PackResultDto
{
    /**
     * @param list<PackCardDto> $cards
     */
    public function __construct(
        public string $id,
        public CardPackKind $kind,
        public array $cards,
        public int $cost,
        /** What the duplicates in this pack paid back, in total. */
        public int $jetonsEarned,
        public int $balance,
        /** True when this pack's duplicates were worth less because the daily cap was hit. */
        public bool $dailyCapReached,
        public string $openedAt,
    ) {
    }
}
