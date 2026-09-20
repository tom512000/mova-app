<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardFeatDto;
use App\Entity\Enum\CardFeat;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\CardSubject;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tallies behind the ten feats.
 *
 * Derived on every request and stored nowhere, exactly as the trophies are — which is what
 * lets the frontend reuse the trophy shelf's locked/unlocked vocabulary wholesale instead of
 * inventing a third achievement idiom.
 *
 * Most of it is one query over the catalogue. Two feats need help: the sets, which come from
 * CardSetService because that is where a set is defined, and the salvage total, which comes
 * off the pack history rather than off `lifetime_earned` — the latter also counts grants and
 * set bonuses, and a feat called "récupération" that counted daily logins would be lying.
 */
final class CardFeatService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardFeatCalculator $calculator,
        private readonly CardSetService $sets,
    ) {
    }

    /**
     * @return list<CardFeatDto>
     */
    public function list(User $user): array
    {
        return $this->calculator->calculate(array_merge(
            $this->catalogueTallies($user),
            $this->setTallies($user)
        ));
    }

    /**
     * @return array<string, int>
     */
    private function catalogueTallies(User $user): array
    {
        $userId = (string) $user->getId();
        $connection = $this->entityManager->getConnection();

        $cards = $connection->executeQuery(
            'SELECT
                COUNT(*) FILTER (WHERE c.copies > 0) AS owned,
                COUNT(*) FILTER (WHERE c.copies > 0 AND COALESCE(c.owned_rarity, c.rarity) = :legendary) AS legendaries,
                COUNT(*) FILTER (WHERE c.copies > 0 AND c.subject = :studio) AS studios
            FROM card c
            WHERE c.user_id = :userId',
            [
                'userId' => $userId,
                'legendary' => CardRarity::LEGENDARY->value,
                'studio' => CardSubject::STUDIO->value,
            ]
        )->fetchAssociative() ?: [];

        // "Auteur" counts owned person cards for somebody who actually directed or created
        // something in this library — not everybody who happens to be a person. DISTINCT on
        // the card, since one person can carry both credits.
        $directors = (int) $connection->executeQuery(
            'SELECT COUNT(DISTINCT c.id)
            FROM card c
            JOIN credit cr ON cr.person_id = c.person_id
            JOIN card work ON work.movie_id = cr.movie_id AND work.user_id = c.user_id
            WHERE c.user_id = :userId
                AND c.subject = :person
                AND c.copies > 0
                AND cr.role IN (:roles)',
            [
                'userId' => $userId,
                'person' => CardSubject::PERSON->value,
                'roles' => [CreditRole::DIRECTOR->value, CreditRole::CREATOR->value],
            ],
            ['roles' => ArrayParameterType::STRING]
        )->fetchOne();

        $packs = $connection->executeQuery(
            'SELECT COUNT(*) AS opened, COALESCE(SUM(o.jetons_earned), 0) AS salvaged
            FROM card_pack_opening o WHERE o.user_id = :userId',
            ['userId' => $userId]
        )->fetchAssociative() ?: [];

        $spent = (int) $connection->executeQuery(
            'SELECT lifetime_spent FROM card_cabinet WHERE user_id = :userId',
            ['userId' => $userId]
        )->fetchOne();

        return [
            CardFeat::COLLECTOR->value => (int) ($cards['owned'] ?? 0),
            CardFeat::FIRST_LEGENDARY->value => (int) ($cards['legendaries'] ?? 0),
            CardFeat::MOGUL->value => (int) ($cards['studios'] ?? 0),
            CardFeat::AUTEUR->value => $directors,
            CardFeat::PACK_RAT->value => (int) ($packs['opened'] ?? 0),
            CardFeat::SALVAGE->value => (int) ($packs['salvaged'] ?? 0),
            CardFeat::BIG_SPENDER->value => $spent,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function setTallies(User $user): array
    {
        $completed = 0;
        $completedSagas = 0;

        foreach ($this->sets->list($user) as $set) {
            if (!$set->complete) {
                continue;
            }

            ++$completed;
            if (\App\Entity\Enum\CardSetFamily::FRANCHISE === $set->family) {
                ++$completedSagas;
            }
        }

        return [
            CardFeat::COMPLETIONIST->value => $completed,
            // Both are single-rung, so "at least one" is the whole ladder.
            CardFeat::FULL_HOUSE->value => $completed,
            CardFeat::SAGA->value => $completedSagas,
        ];
    }
}
