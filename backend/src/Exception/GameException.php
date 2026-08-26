<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A move the rules refuse: guessing twice, guessing after the run is over, or asking for a
 * puzzle a library cannot supply. Always carries a message meant to be shown to the player.
 */
final class GameException extends \RuntimeException
{
}
