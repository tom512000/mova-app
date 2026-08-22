<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum WatchSource: string
{
    case CSV_IMPORT = 'csv_import';
    case RSS_SYNC = 'rss_sync';
    case MANUAL = 'manual';
}
