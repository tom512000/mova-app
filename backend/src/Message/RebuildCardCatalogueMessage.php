<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to rescore one account's card catalogue against its library.
 *
 * The id travels as a string rather than as a Uuid object, for the reason EnrichMovieMessage
 * gives: an envelope is serialised into the queue and can sit there across a deploy, so the
 * payload is kept to scalars that survive any change of PHP class on the other side.
 */
final readonly class RebuildCardCatalogueMessage
{
    public function __construct(
        public string $userId,
    ) {
    }
}
