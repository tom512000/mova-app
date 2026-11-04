<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\MovieListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Letterboxd list, as its export file last described it.
 *
 * Not called `List`, which PHP reserves, nor `Collection`, which Doctrine already puts in
 * every entity file — the same trap Franchise was named to avoid.
 *
 * Identified by its Letterboxd short link rather than by its name. The name is the one thing
 * about a list its owner is most likely to edit, and keying on it would turn a rename into a
 * second list with the first one left behind; the boxd.it link survives a rename, and it is
 * the only identifier the export carries.
 */
#[ORM\Entity(repositoryClass: MovieListRepository::class)]
#[ORM\Table(name: 'movie_list')]
#[ORM\UniqueConstraint(name: 'uniq_movie_list_user_url', fields: ['user', 'letterboxdUrl'])]
#[ORM\Index(name: 'idx_movie_list_user', fields: ['user'])]
class MovieList
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $letterboxdUrl;

    #[ORM\Column(length: 500)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /** The day the list was created on Letterboxd, per the export's "Date" column. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $createdOn = null;

    /** The last import that described this list — a list is a snapshot, refreshed whole. */
    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, MovieListEntry> */
    #[ORM\OneToMany(targetEntity: MovieListEntry::class, mappedBy: 'list', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $entries;

    public function __construct(User $user, string $letterboxdUrl, string $name)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->letterboxdUrl = $letterboxdUrl;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->importedAt = $this->createdAt;
        $this->entries = new ArrayCollection();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLetterboxdUrl(): string
    {
        return $this->letterboxdUrl;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    public function getCreatedOn(): ?\DateTimeImmutable
    {
        return $this->createdOn;
    }

    public function setCreatedOn(?\DateTimeImmutable $createdOn): static
    {
        $this->createdOn = $createdOn;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function markImported(): static
    {
        $this->importedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MovieListEntry>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(MovieListEntry $entry): static
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
        }

        return $this;
    }

    public function removeEntry(MovieListEntry $entry): static
    {
        $this->entries->removeElement($entry);

        return $this;
    }
}
