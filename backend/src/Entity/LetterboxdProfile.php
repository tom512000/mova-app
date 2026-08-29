<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\LetterboxdProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What profile.csv says about the account this library was exported from.
 *
 * Deliberately not folded into User. User is who signs in here — an email, a password, a
 * display name this app owns. This is a snapshot of somebody's Letterboxd page: it is read
 * from a file, replaced wholesale on the next import, and every field in it can be blank
 * because Letterboxd asks for none of them. Keeping the two apart means a re-import can
 * overwrite this without ever going near a credential.
 *
 * One per account, so a second import of profile.csv updates the row rather than adding one.
 */
#[ORM\Entity(repositoryClass: LetterboxdProfileRepository::class)]
#[ORM\Table(name: 'letterboxd_profile')]
class LetterboxdProfile
{
    use HasUuid;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $givenName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $familyName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    /** Free text on Letterboxd ("He / his"), so it is stored as written rather than parsed. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $pronoun = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $joinedOn = null;

    /**
     * The films pinned to the top of the profile, in their slots.
     *
     * @var Collection<int, FavouriteFilm>
     */
    #[ORM\OneToMany(mappedBy: 'profile', targetEntity: FavouriteFilm::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $favourites;

    /** When the file this was read from was imported — the age of everything above. */
    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(User $user)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->favourites = new ArrayCollection();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getGivenName(): ?string
    {
        return $this->givenName;
    }

    public function setGivenName(?string $givenName): static
    {
        $this->givenName = $givenName;

        return $this;
    }

    public function getFamilyName(): ?string
    {
        return $this->familyName;
    }

    public function setFamilyName(?string $familyName): static
    {
        $this->familyName = $familyName;

        return $this;
    }

    /**
     * Both names joined, or null when Letterboxd holds neither — which is the common case,
     * since the field is optional and most accounts leave it empty.
     */
    public function getFullName(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->givenName, $this->familyName])));

        return '' !== $name ? $name : null;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getPronoun(): ?string
    {
        return $this->pronoun;
    }

    public function setPronoun(?string $pronoun): static
    {
        $this->pronoun = $pronoun;

        return $this;
    }

    public function getJoinedOn(): ?\DateTimeImmutable
    {
        return $this->joinedOn;
    }

    public function setJoinedOn(?\DateTimeImmutable $joinedOn): static
    {
        $this->joinedOn = $joinedOn;

        return $this;
    }

    /**
     * @return Collection<int, FavouriteFilm>
     */
    public function getFavourites(): Collection
    {
        return $this->favourites;
    }

    /**
     * Empties the slots so an import can refill them.
     *
     * Favourites are a short ordered list somebody rearranges by hand; working out which of
     * four moved, left or arrived would be more code than dropping them and reading the row
     * again. orphanRemoval on the association is what makes that a delete rather than a leak.
     */
    public function clearFavourites(): static
    {
        $this->favourites->clear();

        return $this;
    }

    public function addFavourite(FavouriteFilm $favourite): static
    {
        if (!$this->favourites->contains($favourite)) {
            $this->favourites->add($favourite);
        }

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function touchImportedAt(): static
    {
        $this->importedAt = new \DateTimeImmutable();

        return $this;
    }
}
