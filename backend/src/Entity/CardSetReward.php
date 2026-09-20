<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\CardSetFamily;
use App\Repository\CardSetRewardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A completion bonus, paid once.
 *
 * The sets themselves are derived and stored nowhere — a set is "every work card whose
 * decade or genre or studio is X", and X lives on columns the catalogue already joins to,
 * so a stored set table would have to be rewritten by the importer, the RSS sync and every
 * manual correction and would drift the first time one of them was missed. That is
 * BadgeService's argument and it holds here unchanged.
 *
 * This is the one thing about a set that cannot be derived: whether its bonus has already
 * been paid. The unique constraint below *is* the idempotency — a second claim is a
 * UniqueConstraintViolationException, caught and answered as "Récompense déjà réclamée."
 * rather than guarded by a read that another request could race.
 *
 * `setKey` is the set's identity as the SQL produces it, which differs by family: a decade
 * is "1990", a genre or country is its name, a studio or saga is its UUID as text. It is
 * stored as given rather than normalised, because it has to match what CardSetService
 * groups on, and two spellings of the same key would pay twice.
 */
#[ORM\Entity(repositoryClass: CardSetRewardRepository::class)]
#[ORM\Table(name: 'card_set_reward')]
#[ORM\UniqueConstraint(name: 'uniq_card_set_reward_user_set', fields: ['user', 'family', 'setKey'])]
class CardSetReward
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: CardSetFamily::class)]
    private CardSetFamily $family;

    #[ORM\Column(length: 200)]
    private string $setKey;

    #[ORM\Column]
    private int $jetons;

    #[ORM\Column]
    private \DateTimeImmutable $claimedAt;

    public function __construct(User $user, CardSetFamily $family, string $setKey, int $jetons)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->family = $family;
        $this->setKey = $setKey;
        $this->jetons = $jetons;
        $this->claimedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFamily(): CardSetFamily
    {
        return $this->family;
    }

    public function getSetKey(): string
    {
        return $this->setKey;
    }

    public function getJetons(): int
    {
        return $this->jetons;
    }

    public function getClaimedAt(): \DateTimeImmutable
    {
        return $this->claimedAt;
    }
}
