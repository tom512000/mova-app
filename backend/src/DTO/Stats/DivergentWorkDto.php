<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class DivergentWorkDto
{
    /**
     * @param float $publicRating TMDB's audience score, halved onto the same five-star scale
     * @param float $gap          yourRating minus publicRating, computed from the two rounded
     *                            values above so the three numbers on a row always add up
     */
    public function __construct(
        public string $movieId,
        public string $title,
        public float $yourRating,
        public float $publicRating,
        public float $gap,
    ) {
    }
}
