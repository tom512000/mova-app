<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardDto;
use App\Entity\Card;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CardSubject;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns a card row into the shape the API sends.
 *
 * Four surfaces build CardDto — the album grid, a pack opening, the showcase and one card's
 * own page — and three of them read raw DBAL rows because they page or group over thousands
 * of cards. Rather than repeat the same casts and the same image-URL concatenation four
 * times, they come through here.
 *
 * The image size is chosen once and here: w342 is the poster width the rest of the app
 * already uses for a card-sized image, and a person's profile photo is served at the same
 * width so a mixed grid does not load two different resolutions.
 */
final class CardMapper
{
    private const IMAGE_WIDTH = 'w342';

    public function __construct(
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function fromRow(array $row): CardDto
    {
        $liveRarity = CardRarity::from((string) $row['rarity']);
        $ownedRarity = null !== ($row['owned_rarity'] ?? null) ? CardRarity::from((string) $row['owned_rarity']) : null;
        $copies = (int) ($row['copies'] ?? 0);

        return new CardDto(
            id: (string) $row['id'],
            subject: CardSubject::from((string) $row['subject']),
            label: (string) $row['label'],
            imageUrl: $this->imageUrl(null !== ($row['image_path'] ?? null) ? (string) $row['image_path'] : null),
            releaseYear: null !== ($row['release_year'] ?? null) ? (int) $row['release_year'] : null,
            workCount: (int) ($row['work_count'] ?? 1),
            // What was pulled, falling back to what it is worth today for a card nobody owns.
            rarity: $ownedRarity ?? $liveRarity,
            liveRarity: $liveRarity,
            percentile: (float) ($row['percentile'] ?? 0),
            copies: $copies,
            owned: $copies > 0,
            inCatalogue: (bool) ($row['in_catalogue'] ?? true),
            showcasePosition: null !== ($row['showcase_position'] ?? null) ? (int) $row['showcase_position'] : null,
        );
    }

    public function fromEntity(Card $card): CardDto
    {
        return new CardDto(
            id: (string) $card->getId(),
            subject: $card->getSubject(),
            label: $card->getLabel(),
            imageUrl: $this->imageUrl($card->getImagePath()),
            releaseYear: $card->getReleaseYear(),
            workCount: $card->getWorkCount(),
            rarity: $card->getDisplayRarity(),
            liveRarity: $card->getRarity(),
            percentile: (float) $card->getPercentile(),
            copies: $card->getCopies(),
            owned: $card->isOwned(),
            inCatalogue: $card->isInCatalogue(),
            showcasePosition: $card->getShowcasePosition(),
        );
    }

    private function imageUrl(?string $path): ?string
    {
        return null !== $path ? $this->imageBaseUrl.'/'.self::IMAGE_WIDTH.$path : null;
    }
}
