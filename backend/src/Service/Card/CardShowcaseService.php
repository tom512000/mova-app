<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardDto;
use App\Entity\User;
use App\Exception\CardException;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The six cards an account puts on show.
 *
 * This is the feature's one social surface, and the reason it exists at all: Mova is
 * single-player by nature — there is no trading floor, no market, nobody to show a
 * Légendaire to except through a shared profile. So the showcase is read through
 * getViewedUser() like the badge shelf, and written through getAuthenticatedUser() like
 * everything else that writes.
 *
 * Slots are positions, not a list. An empty slot three is not the same as a showcase of two
 * cards, and compacting one would quietly rearrange something somebody arranged on purpose.
 */
final class CardShowcaseService
{
    public const SLOTS = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardRepository $cards,
        private readonly CardMapper $mapper,
    ) {
    }

    /**
     * @return list<CardDto|null> exactly SLOTS entries, gaps included
     */
    public function get(User $user): array
    {
        $slots = array_fill(0, self::SLOTS, null);

        foreach ($this->cards->findShowcase($user) as $card) {
            $position = $card->getShowcasePosition();
            if (null !== $position && $position >= 1 && $position <= self::SLOTS) {
                $slots[$position - 1] = $this->mapper->fromEntity($card);
            }
        }

        return $slots;
    }

    /**
     * Replaces the whole showcase in one move.
     *
     * Wholesale rather than slot by slot because the client hands back an arrangement, and
     * applying an arrangement one slot at a time would transiently violate the unique
     * constraint on (user, position) whenever two cards swap places. Clearing first and
     * writing after, inside one transaction, is the only ordering that survives a swap.
     *
     * @param list<string|null> $cardIds up to SLOTS entries; null leaves a slot empty
     *
     * @return list<CardDto|null>
     */
    public function set(User $user, array $cardIds): array
    {
        if (\count($cardIds) > self::SLOTS) {
            throw new CardException(sprintf('La vitrine ne compte que %d emplacements.', self::SLOTS));
        }

        $ids = [];
        foreach ($cardIds as $position => $cardId) {
            if (null === $cardId || '' === $cardId) {
                continue;
            }

            if (!Uuid::isValid($cardId)) {
                throw new CardException('Cette carte n\'existe pas.');
            }

            if (\in_array($cardId, array_values($ids), true)) {
                throw new CardException('Une carte ne peut occuper qu\'un seul emplacement.');
            }

            $ids[$position] = $cardId;
        }

        $owned = [];
        foreach ($this->cards->findOwnedByIds($user, array_map(static fn (string $id) => Uuid::fromString($id), array_values($ids))) as $card) {
            $owned[(string) $card->getId()] = $card;
        }

        foreach ($ids as $cardId) {
            if (!isset($owned[$cardId])) {
                // Same message whether the card belongs to somebody else or does not exist,
                // so a showcase cannot be used to probe another account's catalogue.
                throw new CardException('Cette carte n\'est pas encore dans ta collection.');
            }
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $ids, $owned): void {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE card SET showcase_position = NULL WHERE user_id = :userId AND showcase_position IS NOT NULL',
                ['userId' => (string) $user->getId()]
            );

            foreach ($ids as $position => $cardId) {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE card SET showcase_position = :position WHERE id = :id',
                    ['id' => $cardId, 'position' => $position + 1]
                );
            }

            foreach ($owned as $card) {
                $this->entityManager->detach($card);
            }
        });

        return $this->get($user);
    }
}
