<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Something the Cabinet refuses: opening a pack below the 500-work gate, buying one there
 * are not enough jetons for, claiming a bonus twice, or pinning a card nobody owns. Always
 * carries a message meant to be shown to the player, in French, like GameException.
 */
final class CardException extends \RuntimeException
{
}
