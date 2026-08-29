<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\ImportStatus;
use App\Repository\ImportBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(name: 'idx_import_batch_user', fields: ['user'])]
class ImportBatch
{
    use HasUuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $filename;

    /** Absolute path where the uploaded file was persisted for async processing. */
    #[ORM\Column(length: 500)]
    private string $storedPath;

    #[ORM\Column(length: 20, enumType: ImportFileType::class)]
    private ImportFileType $fileType;

    #[ORM\Column(length: 30, enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $rowsTotal = 0;

    #[ORM\Column]
    private int $rowsImported = 0;

    #[ORM\Column]
    private int $rowsSkipped = 0;

    #[ORM\Column]
    private int $rowsFailed = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ImportRowError> */
    #[ORM\OneToMany(targetEntity: ImportRowError::class, mappedBy: 'importBatch', orphanRemoval: true, cascade: ['persist'])]
    private Collection $rowErrors;

    public function __construct(User $user, string $filename, string $storedPath, ImportFileType $fileType)
    {
        $this->initialiseUuid();
        $this->user = $user;
        $this->filename = $filename;
        $this->storedPath = $storedPath;
        $this->fileType = $fileType;
        $this->startedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->rowErrors = new ArrayCollection();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStoredPath(): string
    {
        return $this->storedPath;
    }

    public function getFileType(): ImportFileType
    {
        return $this->fileType;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function setStatus(ImportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markFinished(): static
    {
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRowsTotal(): int
    {
        return $this->rowsTotal;
    }

    public function setRowsTotal(int $rowsTotal): static
    {
        $this->rowsTotal = $rowsTotal;

        return $this;
    }

    public function getRowsImported(): int
    {
        return $this->rowsImported;
    }

    public function incrementRowsImported(): static
    {
        ++$this->rowsImported;

        return $this;
    }

    public function getRowsSkipped(): int
    {
        return $this->rowsSkipped;
    }

    public function incrementRowsSkipped(): static
    {
        ++$this->rowsSkipped;

        return $this;
    }

    public function getRowsFailed(): int
    {
        return $this->rowsFailed;
    }

    public function incrementRowsFailed(): static
    {
        ++$this->rowsFailed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ImportRowError> */
    public function getRowErrors(): Collection
    {
        return $this->rowErrors;
    }

    public function addRowError(ImportRowError $rowError): static
    {
        if (!$this->rowErrors->contains($rowError)) {
            $this->rowErrors->add($rowError);
        }

        return $this;
    }
}
