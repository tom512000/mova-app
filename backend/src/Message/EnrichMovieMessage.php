<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to (re)fetch a film's TMDB data.
 *
 * The id travels as a string rather than as a Uuid object: an envelope is serialised into
 * the queue and can sit there across a deploy, so the payload is kept to scalars that
 * survive any change of PHP class on the other side.
 */
final readonly class EnrichMovieMessage
{
    public function __construct(
        public string $movieId,
    ) {
    }
}
