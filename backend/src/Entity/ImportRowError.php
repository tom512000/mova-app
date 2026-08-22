<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRowErrorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRowErrorRepository::class)]
#[ORM\Table(name: 'import_row_error')]
class ImportRowError
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportBatch::class, inversedBy: 'rowErrors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ImportBatch $importBatch;

    #[ORM\Column]
    private int $rowNumber;

    #[ORM\Column(type: Types::JSON)]
    private array $rawData;

    #[ORM\Column(type: Types::TEXT)]
    private string $errorMessage;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ImportBatch $importBatch, int $rowNumber, array $rawData, string $errorMessage)
    {
        $this->importBatch = $importBatch;
        $this->rowNumber = $rowNumber;
        $this->rawData = $rawData;
        $this->errorMessage = $errorMessage;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImportBatch(): ImportBatch
    {
        return $this->importBatch;
    }

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
