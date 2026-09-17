<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The shelves trophies are grouped on.
 *
 * Two for now. The catalogue was drawn up in nine families and is being built a couple at a
 * time; a family only gets a case here once it has trophies behind it, so the shelf never
 * draws a heading over nothing.
 */
enum TrophyFamily: string
{
    case REGULARITY = 'regularity';
    case SPECIAL_DATES = 'special_dates';
}
