<?php

declare(strict_types=1);

namespace App\Entity;

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
#[ORM\UniqueConstraint(name: 'uniq_game_session_daily', fields: ['user', 'mode', 'puzzleDate'])]
#[ORM\Index(name: 'idx_game_session_user_mode_status', fields: ['user', 'mode', 'status'])]
class GameSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: GameMode::class)]
    private GameMode $mode;

    #[ORM\ManyToOne(targetEntity: Movie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Movie $movie;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $puzzleDate = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $guesses = [];

    #[ORM\Column(length: 20, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::IN_PROGRESS;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(User $user, GameMode $mode, Movie $movie, ?\DateTimeImmutable $puzzleDate = null)
    {
        $this->user = $user;
        $this->mode = $mode;
        $this->movie = $movie;
        $this->puzzleDate = $puzzleDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
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

    /** @return list<int> */
    public function getGuesses(): array
    {
        return $this->guesses;
    }

    public function hasGuessed(int $movieId): bool
    {
        return \in_array($movieId, $this->guesses, true);
    }

    public function addGuess(int $movieId): static
    {
        $this->guesses[] = $movieId;

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
