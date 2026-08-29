<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Entity\Enum\GameStatus;
use App\Repository\GameSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of the "guess the film" game.
 *
 * The answer lives here and never leaves the server until the run is over — that is the
 * whole reason this is a database row rather than client state. Guesses are stored as the
 * bare movie ids in the order they were made; everything the client sees (clues, titles,
 * how many tries are left) is derived from them at read time.
 */
#[ORM\Entity(repositoryClass: GameSessionRepository::class)]
#[ORM\Table(name: 'game_session')]
// puzzleDate is null for infinite runs, and Postgres lets nulls repeat in a unique index,
// so this constrains the daily mode to one run per day without touching the other.
#[ORM\UniqueConstraint(name: 'uniq_game_session_daily', fields: ['user', 'game', 'mode', 'puzzleDate'])]
#[ORM\Index(name: 'idx_game_session_user_game_mode_status', fields: ['user', 'game', 'mode', 'status'])]
class GameSession
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: GameKind::class)]
    private GameKind $game;

    #[ORM\Column(length: 20, enumType: GameMode::class)]
    private GameMode $mode;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $puzzleDate = null;

    /**
     * The films proposed, in order, as UUID strings. Strings and not Uuid objects because
     * this is a JSON column: what round-trips through it is exactly what json_encode
     * produced, so the stored form is the canonical one and comparisons here are string
     * comparisons.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $guesses = [];

    /**
     * Letters tried, uppercased and unaccented, in the order they were played. Hangman only
     * — it is the one game whose moves are not films, and giving it a column of its own
     * keeps the other three's guesses a plain list of movie ids.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $letters = [];

    #[ORM\Column(length: 20, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::IN_PROGRESS;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(User $user, GameKind $game, GameMode $mode, Movie $movie, ?\DateTimeImmutable $puzzleDate = null)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->game = $game;
        $this->mode = $mode;
        $this->movie = $movie;
        $this->puzzleDate = $puzzleDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGame(): GameKind
    {
        return $this->game;
    }

    public function getMode(): GameMode
    {
        return $this->mode;
    }

    public function getMovie(): Movie
    {
        return $this->movie;
    }

    public function getPuzzleDate(): ?\DateTimeImmutable
    {
        return $this->puzzleDate;
    }

    /** @return list<string> */
    public function getGuesses(): array
    {
        return $this->guesses;
    }

    public function hasGuessed(string $movieId): bool
    {
        return \in_array($movieId, $this->guesses, true);
    }

    public function addGuess(string $movieId): static
    {
        $this->guesses[] = $movieId;

        return $this;
    }

    /** @return list<string> */
    public function getLetters(): array
    {
        return $this->letters;
    }

    public function hasTriedLetter(string $letter): bool
    {
        return \in_array($letter, $this->letters, true);
    }

    public function addLetter(string $letter): static
    {
        $this->letters[] = $letter;

        return $this;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function finish(GameStatus $status): static
    {
        $this->status = $status;
        $this->completedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
