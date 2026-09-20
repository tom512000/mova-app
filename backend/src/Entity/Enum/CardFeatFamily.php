<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The shelves the card feats are grouped on.
 *
 * The same device as TrophyFamily, and for the same reason: ten feats in one flat list read
 * as a to-do list, while four short shelves read as four different ways of playing. What
 * separates them is the verb — collecting, completing, spending, specialising — not the
 * difficulty.
 */
enum CardFeatFamily: string
{
    /** Volume: how much has been pulled, and how often. */
    case COLLECTION = 'collection';

    /** Closure: sets finished, sagas finished. */
    case COMPLETION = 'completion';

    /** The till: jetons spent, jetons salvaged from duplicates. */
    case ECONOMY = 'economy';

    /** Taste: collecting one kind of card on purpose rather than whatever fell out. */
    case SPECIALITY = 'speciality';
}
