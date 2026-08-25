<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\WatchlistEntry;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The two accounts used to exercise login and profile sharing.
 *
 * Deliberately additive and idempotent: it upserts by email and never touches Movie or
 * Watch rows it did not create, so it is safe to run against a real library. Load it with
 *
 *   php bin/console doctrine:fixtures:load --append --group=users
 *
 * `--append` matters — without it Doctrine purges every table first, which would wipe an
 * imported library. The group keeps AppFixtures (the throwaway demo catalogue) out of it.
 *
 * The owner account is the one Version20260825113021 created to hold the pre-multi-user
 * library; that migration left it with an unusable password on purpose, and this is what
 * gives it a real one.
 */
final class UserFixtures extends Fixture implements FixtureGroupInterface
{
    public const OWNER_EMAIL = 'tom.sikora03@gmail.com';
    public const GUEST_EMAIL = 'camille.martin@example.com';

    /**
     * Local-development credential, shared by both accounts so there is one thing to
     * remember while clicking through the sharing flow. Anything reachable from outside
     * localhost needs a real password set before it is exposed.
     */
    public const DEV_PASSWORD = 'letterboxd';

    /** How much of the shared catalogue the guest account gets, so its profile isn't empty. */
    private const GUEST_WATCH_COUNT = 40;
    private const GUEST_WATCHLIST_COUNT = 12;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return string[]
     */
    public static function getGroups(): array
    {
        return ['users'];
    }

    public function load(ObjectManager $manager): void
    {
        $owner = $this->upsertUser($manager, self::OWNER_EMAIL, 'Tom');
        $owner->setLetterboxdUsername($owner->getLetterboxdUsername() ?? 'tom51200');

        $guest = $this->upsertUser($manager, self::GUEST_EMAIL, 'Camille Martin');

        $manager->flush();

        $this->giveGuestSomethingToLookAt($manager, $guest);

        $manager->flush();
    }

    private function upsertUser(ObjectManager $manager, string $email, string $displayName): User
    {
        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            $user = new User($email, $displayName);
            $manager->persist($user);
        }

        $user->setDisplayName($displayName);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEV_PASSWORD));

        return $user;
    }

    /**
     * A profile with nothing in it can't demonstrate sharing — every page would read
     * "aucun film". The guest borrows films from the shared catalogue (Movie rows are not
     * owned by anyone) with its own ratings, so switching profiles visibly changes the
     * dashboard rather than just the name in the header.
     */
    private function giveGuestSomethingToLookAt(ObjectManager $manager, User $guest): void
    {
        $existingWatches = $manager->getRepository(Watch::class)->count(['user' => $guest]);
        if ($existingWatches > 0) {
            return; // already seeded on a previous run
        }

        /** @var Movie[] $movies */
        $movies = $manager->getRepository(Movie::class)->findBy(
            ['enrichmentStatus' => \App\Entity\Enum\EnrichmentStatus::ENRICHED],
            ['id' => 'ASC'],
            self::GUEST_WATCH_COUNT + self::GUEST_WATCHLIST_COUNT
        );

        $watched = \array_slice($movies, 0, self::GUEST_WATCH_COUNT);
        $toWatch = \array_slice($movies, self::GUEST_WATCH_COUNT);

        foreach ($watched as $index => $movie) {
            $watch = new Watch($guest, $movie, WatchSource::CSV_IMPORT);
            // Deterministic rather than random so re-running the fixture, or comparing two
            // machines, gives the same profile. Cycles 2.5 -> 5.0 in half-star steps.
            $watch->setRating(2.5 + (($index % 6) * 0.5));
            $watch->setWatchedDate(new \DateTimeImmutable(sprintf('2026-01-01 +%d days', $index * 5)));
            $manager->persist($watch);
            $movie->addWatch($watch);
        }

        foreach ($toWatch as $index => $movie) {
            $entry = new WatchlistEntry($guest, $movie);
            $entry->setAddedDate(new \DateTimeImmutable(sprintf('2026-06-01 +%d days', $index * 3)));
            $manager->persist($entry);
        }
    }
}
