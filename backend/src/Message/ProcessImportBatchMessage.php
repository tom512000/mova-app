<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to read one uploaded CSV into the library.
 *
 * The id travels as a string rather than as a Uuid object, for the same reason as
 * EnrichMovieMessage: an envelope outlives the request that queued it.
 */
final readonly class ProcessImportBatchMessage
{
    public function __construct(
        public string $importBatchId,
    ) {
    }
}
