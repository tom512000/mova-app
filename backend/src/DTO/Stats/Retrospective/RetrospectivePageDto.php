<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/**
 * The whole page in one response, years included.
 *
 * Together rather than behind two endpoints because the client cannot know which year to ask
 * for until it knows which years exist. Split apart, every visit would wait for one request
 * to learn what to put in the next.
 */
final readonly class RetrospectivePageDto
{
    public function __construct(
        /**
         * Years with viewings in them, most recent first. Empty on a library that has never
         * been imported, or one whose viewings carry no dates.
         *
         * @var list<int>
         */
        public array $availableYears,
        /** Null when there is no year to show at all. */
        public ?RetrospectiveDto $retrospective,
    ) {
    }
}
