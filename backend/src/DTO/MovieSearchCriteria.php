<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\MovieSortField;

final readonly class MovieSearchCriteria
{
    /**
     * @param string|null     $query      matched against the title *and* the original title
     * @param float|null      $rating     exact half-star value; keeps films rated exactly that
     * @param bool            $unratedOnly mutually exclusive with $rating, and wins over it
     * @param string|null     $watchedOn  ISO date; keeps films watched that very day, which is
     *                                    how a square of the activity calendar narrows the
     *                                    library down to what it counted
     * @param string|null     $personId   keeps films this person is credited on
     * @param CreditRole|null $personRole narrows that credit to one role; any role when null
     * @param MediaType|null  $mediaType  keeps only films, or only series; both when null,
     *                                    which is the default everywhere the library is
     *                                    browsed as a whole
     * @param string|null     $seed       shuffle seed; only read when sorting randomly, and
     *                                    required there so paging through a shuffle is stable
     */
    public function __construct(
        public ?string $query = null,
        public ?string $genre = null,
        public ?int $year = null,
        public ?float $rating = null,
        public bool $unratedOnly = false,
        public ?string $watchedOn = null,
        public ?string $personId = null,
        public ?CreditRole $personRole = null,
        public ?MediaType $mediaType = null,
        public MovieSortField $sort = MovieSortField::TITLE,
        public bool $descending = false,
        public ?string $seed = null,
        public int $page = 1,
        public int $perPage = 24,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
