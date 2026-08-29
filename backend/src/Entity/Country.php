<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\HasUuid;
use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'country')]
#[ORM\UniqueConstraint(name: 'uniq_country_iso_code', fields: ['isoCode'])]
class Country
{
    use HasUuid;

    #[ORM\Column(length: 2)]
    private string $isoCode;

    #[ORM\Column(length: 150)]
    private string $name;

    public function __construct()
    {
        $this->initialiseUuid();
    }

    public function getIsoCode(): string
    {
        return $this->isoCode;
    }

    public function setIsoCode(string $isoCode): static
    {
        $this->isoCode = $isoCode;

        return $this;
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
}
