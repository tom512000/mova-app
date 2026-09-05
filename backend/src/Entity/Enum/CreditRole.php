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

    /**
     * Whoever actually produced the film, and only them.
     *
     * TMDB files half a dozen jobs under its Production department - Producer, Executive
     * Producer, Co-Producer, Associate Producer, Line Producer - and they do not mean the
     * same thing. An executive producer credit is very often a financing arrangement or a
     * name attached to open doors, so counting those would fill a "most-watched producers"
     * ranking with studio executives who were never on a set. Only the plain "Producer" job
     * is kept, which is the same reasoning that keeps CREATOR apart from DIRECTOR: a
     * ranking is only worth reading if every row earned its place the same way.
     */
    case PRODUCER = 'producer';
}
