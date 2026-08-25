<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class CountryStatDto
{
    public function __construct(
        public string $countryName,
        public string $isoCode,
        public int $movieCount,
        public ?float $averageRating,
    ) {
    }
}
