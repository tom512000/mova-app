<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by an Importer to signal a row that is intentionally not imported
 * (e.g. a ratings.csv row already fully covered by a diary.csv entry) —
 * counted as "skipped", not as a failure, and never recorded as a row error.
 */
final class ImportRowSkippedException extends \RuntimeException
{
}
