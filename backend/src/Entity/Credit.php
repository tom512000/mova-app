<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\CreditRole;
use App\Repository\CreditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditRepository::class)]
#[ORM\Table(name: 'credit')]
#[ORM\UniqueConstraint(name: 'uniq_credit_movie_person_role_character', fields: ['movie', 'person', 'role', 'characterName'])]
#[ORM\Index(name: 'idx_credit_role', fields: ['role'])]
class Credit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Movie::class, inversedBy: 'credits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'credits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(length: 20, enumType: CreditRole::class)]
    private CreditRole $role;

    /**
     * TEXT rather than a short varchar: TMDB sometimes concatenates many roles for one
     * person on an animated/ensemble film (e.g. a prolific voice actor), which can run
     * well past a typical 255-char limit (observed against real data).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $characterName = null;

    #[ORM\Column(nullable: true)]
    private ?int $castOrder = null;

    public function __construct(Movie $movie, Person $person, CreditRole $role)
    {
        $this->movie = $movie;
        $this->person = $person;
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getRole(): CreditRole
    {
        return $this->role;
    }

    public function getCharacterName(): ?string
    {
        return $this->characterName;
    }

    public function setCharacterName(?string $characterName): static
    {
        $this->characterName = $characterName;

        return $this;
    }

    public function getCastOrder(): ?int
    {
        return $this->castOrder;
    }

    public function setCastOrder(?int $castOrder): static
    {
        $this->castOrder = $castOrder;

        return $this;
    }
}
