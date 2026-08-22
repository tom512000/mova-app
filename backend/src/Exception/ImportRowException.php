<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by an Importer for a single malformed/unusable row. Caught by the
 * orchestrator so one bad row never aborts the rest of the batch.
 */
final class ImportRowException extends \RuntimeException
{
}
