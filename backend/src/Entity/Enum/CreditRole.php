<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CreditRole: string
{
    case DIRECTOR = 'director';
    case WRITER = 'writer';
    case ACTOR = 'actor';
}
