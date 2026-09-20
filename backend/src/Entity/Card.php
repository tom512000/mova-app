<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\CardSubject;
use App\Repository\CardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One card in one account's catalogue: both the thing that can be pulled and the record of
 * having pulled it.
 *
 * Badges and trophies are derived and stored nowhere, and BadgeService argues at length for
 * why that is the better default here. That argument does not transfer to this table, for
 * three reasons worth stating rather than discovering later:
 *
 *  1. Ownership is not derivable. `copies`, `firstOwnedAt`, `ownedRarity` and
 *     `showcasePosition` are written by pack openings. Nothing in the library reproduces
 *     them, and once ownership has to be a row, the catalogue row is where it belongs.
 *  2. The draw has to be one indexed statement. "Every person reachable through a credit on
 *     a watched work, scored against the works they appear in" is a four-CTE aggregate over
 *     every credit row in the library; evaluated once per card per pack it is unusable.
 *     Materialised it becomes a single index scan on (user, rarity).
 *  3. A percentile band is a fact about the whole set. Rarity cannot be computed for one
 *     row — it has to be computed for all of them at once, which *is* a materialisation.
 *
 * The rows are written by raw SQL, not through this class. CardCatalogueBuilder upserts them
 * in chunks and CardPackOpener increments `copies` with one statement that also reports
 * whether the card was new; hydrating an entity to add one to an integer would be a read, a
 * unit-of-work registration and a flush for what one atomic UPDATE does. So what this class
 * carries is the mapping, the schema the migration mirrors, and the getters the few
 * single-row reads need — not the write path.
 *
 * Two rarities, and this is the load-bearing decision of the whole feature. `rarity` floats
 * with the library because the pack odds are percentile bands, and a frozen rarity would let
 * the Légendaire band drift until it was no longer 0.6 % of anything. `ownedRarity` freezes
 * at the first pull, because a Légendaire pulled in March that became Rare in June after an
 * import would be a retroactive confiscation. Everything that displays a collection reads
 * COALESCE(owned_rarity, rarity); everything that pays for a duplicate reads `rarity`, so a
 * stale frozen tier cannot print jetons forever.
 */
#[ORM\Entity(repositoryClass: CardRepository::class)]
#[ORM\Table(name: 'card')]
// Four constraints and not one, because there are four nullable foreign keys and not one
// polymorphic id: a film removed by a corrected import takes its card with it rather than
// leaving a row pointing at nothing. Postgres lets nulls repeat inside a unique index, which
// is what makes four partial identities coexist in one table — the same property
// GameSession's daily constraint already leans on. These are also the four ON CONFLICT
// targets the catalogue rebuild upserts against.
#[ORM\UniqueConstraint(name: 'uniq_card_user_movie', fields: ['user', 'movie'])]
#[ORM\UniqueConstraint(name: 'uniq_card_user_person', fields: ['user', 'person'])]
#[ORM\UniqueConstraint(name: 'uniq_card_user_studio', fields: ['user', 'studio'])]
#[ORM\UniqueConstraint(name: 'uniq_card_user_franchise', fields: ['user', 'franchise'])]
#[ORM\UniqueConstraint(name: 'uniq_card_user_showcase', fields: ['user', 'showcasePosition'])]
// The draw filters on (user, rarity) and orders within it; the album pages on
// (user, catalogue_rank); the facet counts group on (user, subject).
#[ORM\Index(name: 'idx_card_user_rarity', fields: ['user', 'rarity'])]
#[ORM\Index(name: 'idx_card_user_rank', fields: ['user', 'catalogueRank'])]
#[ORM\Index(name: 'idx_card_user_subject', fields: ['user', 'subject'])]
class Card
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: CardSubject::class)]
    private CardSubject $subject;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Movie $movie = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Person $person = null;

    #[ORM\ManyToOne(targetEntity: Studio::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Studio $studio = null;

    #[ORM\ManyToOne(targetEntity: Franchise::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Franchise $franchise = null;

    /**
     * The title or name, copied here rather than joined.
     *
     * Denormalised on purpose: the album is a mixed-subject grid of several thousand rows,
     * sorted and searched by name and paged. Doing that through a four-way LEFT JOIN with a
     * COALESCE over four title columns, on every read, is the query this codebase would
     * regret. The rebuild rewrites it, so it cannot go stale for longer than a rebuild.
     */
    #[ORM\Column(length: 500)]
    private string $label;

    /**
     * Poster, profile photo or saga poster — the path only, as everywhere else, with the
     * base URL coming from %app.tmdb.image_base_url%.
     *
     * Always null for a studio: Studio holds a tmdbId and a name and no logo was ever
     * imported. The card face draws a typographic plate whenever this is null, so a studio
     * is not a special case but the common case of a shared fallback.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    /** Work cards only; printed on the face beside the title. */
    #[ORM\Column(nullable: true)]
    private ?int $releaseYear = null;

    /**
     * How many watched works this card stands on: 1 for a work, N for a person, studio or
     * saga. Printed on the face, and the number the sets count.
     */
    #[ORM\Column]
    private int $workCount = 1;

    /**
     * 0 to 100.
     *
     * DECIMAL and not a float so that two rebuilds over an unchanged library produce exactly
     * the same ordering. A float's last bits would reshuffle ties between runs, and the
     * catalogue rank is supposed to be stable enough that the album does not rearrange
     * itself between two visits.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $score = '0.000';

    /** 0 to 1. Kept so the face can print "top 0,4 %" and so a re-score is inspectable. */
    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 6)]
    private string $percentile = '0.000000';

    /**
     * 1..N by descending score. The album's stable ordering — winning a card fills a slot
     * rather than reshuffling the shelf, which is the same property TrophyShelf relies on.
     */
    #[ORM\Column]
    private int $catalogueRank = 0;

    /** The live tier. Floats with the library. See the class docblock. */
    #[ORM\Column(length: 20, enumType: CardRarity::class)]
    private CardRarity $rarity = CardRarity::COMMON;

    /** The tier frozen at the first pull. Null until the card is owned. */
    #[ORM\Column(length: 20, nullable: true, enumType: CardRarity::class)]
    private ?CardRarity $ownedRarity = null;

    /** 0 means the card exists in the catalogue but has never been pulled. */
    #[ORM\Column]
    private int $copies = 0;

    /** Powers "dernières trouvailles" and dates the feats, without a separate ledger. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstOwnedAt = null;

    /** 1 to 6 — the slot this card occupies in the account's showcase, or null. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $showcasePosition = null;

    /**
     * False once the card's subject is no longer reachable from the library.
     *
     * A rebuild deletes unowned cards that fell out, but an owned one becomes a relic:
     * undrawable, still in the album under its own heading. That is the only humane answer
     * when a corrected Letterboxd slug would otherwise delete somebody's Légendaire.
     */
    #[ORM\Column]
    private bool $inCatalogue = true;

    /** The mark-and-sweep marker: rows the current rebuild did not touch fell out of it. */
    #[ORM\Column]
    private \DateTimeImmutable $scoredAt;

    public function __construct(User $user, CardSubject $subject, string $label)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->subject = $subject;
        $this->label = $label;
        $this->scoredAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSubject(): CardSubject
    {
        return $this->subject;
    }

    public function getMovie(): ?Movie
    {
        return $this->movie;
    }

    public function setMovie(?Movie $movie): static
    {
        $this->movie = $movie;

        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getStudio(): ?Studio
    {
        return $this->studio;
    }

    public function setStudio(?Studio $studio): static
    {
        $this->studio = $studio;

        return $this;
    }

    public function getFranchise(): ?Franchise
    {
        return $this->franchise;
    }

    public function setFranchise(?Franchise $franchise): static
    {
        $this->franchise = $franchise;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getReleaseYear(): ?int
    {
        return $this->releaseYear;
    }

    public function setReleaseYear(?int $releaseYear): static
    {
        $this->releaseYear = $releaseYear;

        return $this;
    }

    public function getWorkCount(): int
    {
        return $this->workCount;
    }

    public function setWorkCount(int $workCount): static
    {
        $this->workCount = $workCount;

        return $this;
    }

    public function getScore(): string
    {
        return $this->score;
    }

    public function setScore(string $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getPercentile(): string
    {
        return $this->percentile;
    }

    public function setPercentile(string $percentile): static
    {
        $this->percentile = $percentile;

        return $this;
    }

    public function getCatalogueRank(): int
    {
        return $this->catalogueRank;
    }

    public function setCatalogueRank(int $catalogueRank): static
    {
        $this->catalogueRank = $catalogueRank;

        return $this;
    }

    public function getRarity(): CardRarity
    {
        return $this->rarity;
    }

    public function setRarity(CardRarity $rarity): static
    {
        $this->rarity = $rarity;

        return $this;
    }

    public function getOwnedRarity(): ?CardRarity
    {
        return $this->ownedRarity;
    }

    /**
     * The tier a collection displays: what was pulled, falling back to what it is worth
     * today for a card nobody owns yet.
     */
    public function getDisplayRarity(): CardRarity
    {
        return $this->ownedRarity ?? $this->rarity;
    }

    public function getCopies(): int
    {
        return $this->copies;
    }

    public function isOwned(): bool
    {
        return $this->copies > 0;
    }

    public function getFirstOwnedAt(): ?\DateTimeImmutable
    {
        return $this->firstOwnedAt;
    }

    public function getShowcasePosition(): ?int
    {
        return $this->showcasePosition;
    }

    public function setShowcasePosition(?int $showcasePosition): static
    {
        $this->showcasePosition = $showcasePosition;

        return $this;
    }

    public function isInCatalogue(): bool
    {
        return $this->inCatalogue;
    }

    public function setInCatalogue(bool $inCatalogue): static
    {
        $this->inCatalogue = $inCatalogue;

        return $this;
    }

    public function getScoredAt(): \DateTimeImmutable
    {
        return $this->scoredAt;
    }
}
