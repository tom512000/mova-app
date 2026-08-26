<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GameStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case WON = 'won';
    case LOST = 'lost';

    public function isOver(): bool
    {
        return self::IN_PROGRESS !== $this;
    }
}
