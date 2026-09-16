<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\PersonSortField;

final readonly class PersonSearchCriteria
{
    /**
     * @param string|null     $query     matched against the person's name
     * @param CreditRole|null $role      keeps people holding this job, and — this is the
     *                                   point of the filter — counts and rates them on that
     *                                   job alone. Asking for directors must not let an
     *                                   actor's forty appearances inflate their four films
     * @param MediaType|null  $mediaType counts films only, or series only; both when null
     * @param string|null     $seed      shuffle seed; only read when sorting randomly, and
     *                                   required there so paging through a shuffle is stable
     */
    public function __construct(
        public ?string $query = null,
        public ?CreditRole $role = null,
        public ?MediaType $mediaType = null,
        public PersonSortField $sort = PersonSortField::WORKS,
        public bool $descending = true,
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
