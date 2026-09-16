<?php

declare(strict_types=1);

namespace App\DTO\Badge;

use App\Entity\Enum\BadgeCategory;

/**
 * One badge, at the level it currently stands.
 *
 * A badge is a subject, not a trophy per threshold: "Comédie" is one badge that climbs to
 * level 3 rather than three badges called Comédie. That is what the level number on the
 * medallion is for, and it keeps a shelf of six hundred from becoming one of two thousand.
 *
 * Nothing here is stored. Every field is derived from the watch rows on request, so a badge
 * appears the moment an import crosses its threshold and a corrected import takes it back —
 * there is no earned-badge table to drift out of step with the library it describes.
 */
final readonly class BadgeDto
{
    public function __construct(
        public BadgeCategory $category,
        /**
         * What the badge is about, as the client needs it to link back: a person's or a
         * studio's UUID, a genre's or a country's name, a decade's first year, a budget
         * bracket's index. Not every one of them leads anywhere — see the frontend.
         */
        public string $subjectId,
        /** The subject's own name. Budget brackets carry their index; the client words them. */
        public string $label,
        public int $level,
        /** Works counted, which is what the level is floor-divided from. */
        public int $workCount,
        /** How many more works the next level needs. Never null: there is always a next one. */
        public int $worksToNextLevel,
        /**
         * The day the current level was reached — the watch date of the work that crossed
         * it, not the day the badge was looked at. Null when that work carries no date,
         * which Letterboxd exports allow.
         */
        public ?string $earnedOn,
        /**
         * A still from one of the works that earned it, picked once and stably from the
         * badge's own subject — so a badge keeps its face between two visits. Null for the
         * rare subject whose every work lacks artwork; the medallion then falls back to its
         * initials.
         */
        public ?string $imageUrl,
    ) {
    }
}
