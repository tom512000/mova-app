<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LetterboxdSyncStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per Letterboxd username ever synced via RSS (in practice, always one row —
 * this app tracks a single personal account). Kept as its own small entity rather than
 * app-wide config so the API can expose "last synced at / did it fail" without touching
 * ImportBatch, which is CSV-specific.
 */
#[ORM\Entity(repositoryClass: LetterboxdSyncStateRepository::class)]
#[ORM\Table(name: 'letterboxd_sync_state')]
#[ORM\UniqueConstraint(name: 'uniq_sync_state_username', fields: ['username'])]
class LetterboxdSyncState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $username;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** 'success' or 'failed'; null before the first run ever happens. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastSyncStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastSyncError = null;

    #[ORM\Column]
    private int $lastRunWatchesImported = 0;

    public function __construct(string $username)
    {
        $this->username = $username;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function getLastSyncStatus(): ?string
    {
        return $this->lastSyncStatus;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    public function getLastRunWatchesImported(): int
    {
        return $this->lastRunWatchesImported;
    }

    public function markSuccess(int $watchesImported): static
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastSyncStatus = 'success';
        $this->lastSyncError = null;
        $this->lastRunWatchesImported = $watchesImported;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastSyncStatus = 'failed';
        $this->lastSyncError = $error;
        $this->lastRunWatchesImported = 0;

        return $this;
    }
}
