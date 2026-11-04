<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\ListEntrySortField;
use App\Entity\Enum\MediaType;

final readonly class ListEntrySearchCriteria
{
    /**
     * @param bool|null $watched true for what the profile has seen, false for what it has
     *                           not, null for the whole list
     */
    public function __construct(
        public ?string $query = null,
        public ?bool $watched = null,
        public ?MediaType $mediaType = null,
        public ?string $genre = null,
        public ListEntrySortField $sort = ListEntrySortField::POSITION,
        public bool $descending = false,
        public string $seed = '',
        public int $page = 1,
        public int $perPage = 24,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
