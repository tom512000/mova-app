<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CreditRole: string
{
    case DIRECTOR = 'director';

    /**
     * Whoever a series is *by*, which is not the same job as directing it.
     *
     * TMDB models this apart too: a film's director comes out of `crew` with job "Director",
     * while a series carries `created_by` and hands its episode directors out per episode —
     * which this app does not import at all. Folding the two together, as it briefly did,
     * put Pierre Niney among the directors because he co-created Fiasco. He did not direct
     * it, and no stat about directors should have counted him.
     */
    case CREATOR = 'creator';

    case WRITER = 'writer';
    case ACTOR = 'actor';
}
