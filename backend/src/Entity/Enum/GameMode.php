<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GameMode: string
{
    /** One puzzle per calendar day, the same film from the first play to midnight. */
    case DAILY = 'daily';

    /** A fresh film whenever the player asks for one. */
    case INFINITE = 'infinite';
}
