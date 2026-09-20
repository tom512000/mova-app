<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\DTO\Card\CardSetDto;
use App\Entity\CardSetReward;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CardSetFamily;
use App\Entity\User;
use App\Exception\CardException;
use App\Repository\CardSetRewardRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets to complete, and the bonus for finishing one.
 *
 * **Derived, and for BadgeService's reason.** A set is "every work card whose decade, genre,
 * country, studio or saga is X", and X lives on columns the catalogue already joins to. A
 * stored set table would have to be rewritten by the importer, the RSS sync and every manual
 * correction, and it would drift the first time one of them was missed.
 *
 * The one thing that cannot be derived is whether the bonus has already been paid, and that
 * is the only thing stored — see CardSetReward, where the unique constraint *is* the
 * idempotency.
 *
 * Two things are deliberately shared verbatim with BadgeService rather than rewritten: the
 * decade expression, so the two features cannot disagree about what a decade is, and the
 * stable cover-image draw, so a set keeps the same face between two visits.
 */
final class CardSetService
{
    private const IMAGE_WIDTH = 'w342';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardSetRewardRepository $rewards,
        private readonly CardCabinetService $cabinets,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * @return list<CardSetDto>
     */
    public function list(User $user, ?CardSetFamily $only = null): array
    {
        $claimed = $this->rewards->claimedKeys($user);
        $sets = [];

        foreach (null !== $only ? [$only] : CardSetFamily::cases() as $family) {
            foreach ($this->rowsFor($user, $family) as $row) {
                $sets[] = $this->toDto($family, $row, $claimed[$family->value] ?? []);
            }
        }

        // Nearly finished first: the sets worth looking at are the ones a pack could close.
        usort($sets, static function (CardSetDto $a, CardSetDto $b) {
            $left = $a->complete && !$a->claimed;
            $right = $b->complete && !$b->claimed;
            if ($left !== $right) {
                return $left ? -1 : 1;
            }

            return ($b->owned / max(1, $b->total)) <=> ($a->owned / max(1, $a->total));
        });

        return $sets;
    }

    /**
     * Pays a completed set's bonus, once and only once.
     *
     * The insert is attempted and its failure caught, rather than the reward being looked up
     * first: a check followed by a write is two statements a second request can slip between,
     * and the unique constraint is the only guard that closes that window properly.
     */
    public function claim(User $user, CardSetFamily $family, string $key): int
    {
        $set = null;
        foreach ($this->list($user, $family) as $candidate) {
            if ($candidate->key === $key) {
                $set = $candidate;
                break;
            }
        }

        if (null === $set) {
            throw new CardException('Cette série n\'existe pas.');
        }

        if (!$set->complete) {
            throw new CardException(sprintf(
                'Cette série n\'est pas terminée : %d cartes sur %d.',
                $set->owned,
                $set->total
            ));
        }

        try {
            $this->entityManager->persist(new CardSetReward($user, $family, $key, $set->bonus));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new CardException('Récompense déjà réclamée.');
        }

        return $this->cabinets->credit($user, $set->bonus);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(User $user, CardSetFamily $family): array
    {
        [$join, $key, $label, $filter] = $this->shapeOf($family);

        $member = "SELECT {$key} AS set_key, {$label} AS label, c.id, c.copies,
                COALESCE(c.owned_rarity, c.rarity) AS rarity, c.image_path
            FROM card c
            JOIN movie m ON m.id = c.movie_id
            {$join}
            WHERE c.user_id = :userId AND c.subject = 'work' AND c.in_catalogue {$filter}";

        if (CardSetFamily::FRANCHISE === $family) {
            // A saga's own card is the capstone of the films in it, so it counts as a member
            // of its own set. It is the only subject that belongs to a set of work cards,
            // which is why it is a union here rather than a sixth branch of shapeOf().
            $member .= " UNION ALL
                SELECT c.franchise_id::text, f.name, c.id, c.copies,
                    COALESCE(c.owned_rarity, c.rarity), c.image_path
                FROM card c
                JOIN franchise f ON f.id = c.franchise_id
                WHERE c.user_id = :userId AND c.subject = 'franchise' AND c.in_catalogue";
        }

        return $this->entityManager->getConnection()->executeQuery(
            "WITH member AS ({$member})
            SELECT set_key, label,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE copies > 0) AS owned,
                COUNT(*) FILTER (WHERE rarity IN (:rarePlus)) AS rare_plus,
                COUNT(*) FILTER (WHERE rarity IN (:rarePlus) AND copies > 0) AS owned_rare_plus,
                -- The set's face, drawn once and stably by hashing the set with each card,
                -- exactly as BadgeService draws a badge's: a set that changed picture between
                -- two visits would not read as the same set.
                (ARRAY_AGG(image_path ORDER BY md5(set_key || id::text))
                    FILTER (WHERE image_path IS NOT NULL))[1] AS image_path
            FROM member
            GROUP BY set_key, label
            HAVING COUNT(*) >= :minimumSize",
            [
                'userId' => (string) $user->getId(),
                'rarePlus' => array_map(
                    static fn (CardRarity $rarity) => $rarity->value,
                    CardRarity::RARE->andAbove()
                ),
                'minimumSize' => CardSetFamily::MINIMUM_SIZE,
            ],
            ['rarePlus' => ArrayParameterType::STRING]
        )->fetchAllAssociative();
    }

    /**
     * How a work hangs off a set, per family: the join, the set's identity, its name, and
     * what to leave out. The same four-part shape BadgeService::shapeOf() uses.
     *
     * @return array{string, string, string, string}
     */
    private function shapeOf(CardSetFamily $family): array
    {
        return match ($family) {
            // Copied from BadgeService verbatim so the badge shelf and the album cannot
            // disagree about what a decade is. Integer division works because release_year
            // is an int column.
            CardSetFamily::DECADE => [
                '',
                '((m.release_year / 10) * 10)::text',
                '((m.release_year / 10) * 10)::text',
                'AND m.release_year IS NOT NULL',
            ],
            CardSetFamily::GENRE => [
                'JOIN movie_genre mg ON mg.movie_id = c.movie_id JOIN genre g ON g.id = mg.genre_id',
                'g.name', 'g.name', '',
            ],
            CardSetFamily::COUNTRY => [
                'JOIN movie_country mc ON mc.movie_id = c.movie_id JOIN country co ON co.id = mc.country_id',
                'co.name', 'co.name', '',
            ],
            CardSetFamily::STUDIO => [
                'JOIN movie_studio ms ON ms.movie_id = c.movie_id JOIN studio s ON s.id = ms.studio_id',
                's.id::text', 's.name', '',
            ],
            CardSetFamily::FRANCHISE => [
                'JOIN franchise f ON f.id = m.franchise_id',
                'f.id::text', 'f.name', 'AND m.franchise_id IS NOT NULL',
            ],
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $claimedKeys
     */
    private function toDto(CardSetFamily $family, array $row, array $claimedKeys): CardSetDto
    {
        $total = (int) $row['total'];
        $owned = (int) $row['owned'];
        $key = (string) $row['set_key'];

        return new CardSetDto(
            family: $family,
            key: $key,
            label: (string) $row['label'],
            total: $total,
            owned: $owned,
            rarePlus: (int) $row['rare_plus'],
            ownedRarePlus: (int) $row['owned_rare_plus'],
            complete: $owned === $total,
            claimed: \in_array($key, $claimedKeys, true),
            bonus: JetonTariff::setBonus($total, (int) $row['rare_plus']),
            imageUrl: null !== $row['image_path']
                ? $this->imageBaseUrl.'/'.self::IMAGE_WIDTH.$row['image_path']
                : null,
        );
    }
}
