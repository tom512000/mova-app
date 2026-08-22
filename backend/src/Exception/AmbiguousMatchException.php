<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when no TMDB match could be resolved with enough confidence,
 * neither via search nor via the Letterboxd film page fallback.
 */
final class AmbiguousMatchException extends TmdbException
{
}
