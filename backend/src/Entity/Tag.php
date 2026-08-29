<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'uniq_tag_name', fields: ['name'])]
class Tag
{
    use HasUuid;

    #[ORM\Column(length: 100)]
    private string $name;

    public function __construct(string $name)
    {
        $this->initialiseUuid();
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
