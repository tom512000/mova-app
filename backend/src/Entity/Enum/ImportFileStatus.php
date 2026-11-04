<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What an upload did with each file it contained.
 *
 * Two kinds of "not read" are kept apart because they call for different work. An
 * UNSUPPORTED file was opened and offered to every importer, none of which recognised it:
 * supporting it means writing one more importer. An IGNORED file was never offered at all —
 * it sits in a subfolder, or is not a CSV — and supporting it means first deciding that the
 * upload should look there.
 */
enum ImportFileStatus: string
{
    /** An importer recognised it and an ImportBatch is waiting for the worker. */
    case QUEUED = 'queued';

    /** A root-level CSV that no importer recognises yet. */
    case UNSUPPORTED = 'unsupported';

    /** Never offered to the importers: a subfolder entry, or not a CSV. */
    case IGNORED = 'ignored';
}
