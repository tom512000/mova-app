<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum EnrichmentStatus: string
{
    case PENDING = 'pending';
    case ENRICHED = 'enriched';
    case FAILED = 'failed';
    case AMBIGUOUS = 'ambiguous';
}
