<?php

declare(strict_types=1);

namespace App\DTO\Letterboxd;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Letterboxd account this profile syncs from, as the account screen sets it.
 *
 * These two settings were once properties of the installation, which could only ever
 * describe one account. They moved onto the User row when the app became multi-user — but
 * nothing was ever built to write them, so in practice they stayed frozen at whatever the
 * migration had seeded, and a second account had no way to sync at all. This is the missing
 * half.
 */
final readonly class SyncSettingsRequest
{
    /**
     * What makes a username safe to put in a URL, and nothing more than that.
     *
     * The value is interpolated straight into https://letterboxd.com/{username}/rss/. While
     * only a migration could set it that was a closed question; the moment somebody can type
     * it, an unvalidated username is a way to point the server's own fetch somewhere else —
     * `..%2F..%2Felsewhere` and friends. So this is an allow-list: none of the characters
     * below can express a path segment, a query string or a host.
     *
     * Deliberately *not* a copy of Letterboxd's signup rules. Those are unobservable from
     * here and can change, and the two kinds of mistake are not symmetrical — accepting a
     * username Letterboxd would refuse costs a clear 404 from the feed fetch, while refusing
     * one it allows locks somebody out of the feature with a message insisting they are
     * wrong. Hyphens are admitted on exactly that reasoning: a hyphen is as inert in a path
     * segment as an underscore, so there is nothing to buy by rejecting it and a real user
     * to lose if the guess is wrong. The bounds are wide for the same reason.
     */
    public const USERNAME_PATTERN = '/^[a-zA-Z0-9_-]{2,32}$/';

    public function __construct(
        /**
         * Null or empty clears the setting, which is how somebody stops syncing altogether
         * rather than having to invent a username to escape it.
         */
        #[Assert\Regex(
            pattern: self::USERNAME_PATTERN,
            message: 'Un pseudo Letterboxd ne contient que des lettres, chiffres, tirets et tirets bas (2 à 32 caractères).',
        )]
        public ?string $letterboxdUsername = null,

        public bool $rssSyncEnabled = false,
    ) {
    }
}
