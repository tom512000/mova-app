<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\DTO\Person\PersonProfileDto;
use App\DTO\Person\PersonRoleDto;
use App\DTO\Person\PersonWorkDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Everything a person's page shows without asking TMDB anything.
 *
 * The page exists because a filtered listing was never one: clicking a name gave back the
 * library narrowed to that name *in that role*, so Dany Boon was four different links to
 * four different lists and none of them said he was the same person. What is gathered here
 * is the whole of him at once — every job, every work, and how this profile rates him.
 *
 * Two queries rather than one per section. A person holds at most a few dozen credits, so
 * the works are fetched whole and the roles counted from them in PHP, which costs nothing
 * and saves repeating the joins in a second aggregate.
 */
final class PersonProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    public function getProfile(Person $person, User $user): PersonProfileDto
    {
        $works = $this->works($person, $user);
        $watched = array_values(array_filter($works, static fn (PersonWorkDto $work) => $work->watched));

        $rated = array_values(array_filter(
            array_map(static fn (PersonWorkDto $work) => $work->myAverageRating, $watched),
            static fn (?float $rating) => null !== $rating
        ));
        $averageRating = [] === $rated ? null : round(array_sum($rated) / \count($rated), 2);

        return new PersonProfileDto(
            id: (string) $person->getId(),
            name: $person->getName(),
            tmdbId: $person->getTmdbId(),
            profileUrl: null !== $person->getProfilePath()
                ? "{$this->imageBaseUrl}/w300{$person->getProfilePath()}"
                : null,
            roles: $this->roles($works),
            watchedCount: \count($watched),
            watchlistCount: \count(array_filter($works, static fn (PersonWorkDto $work) => $work->inWatchlist)),
            averageRating: $averageRating,
            ratingGap: $this->gapAgainstLibrary($user, $averageRating),
            works: $works,
        );
    }

    /**
     * Every work of theirs the library holds, one row per work however many credits they
     * carry on it.
     *
     * The watchlist is joined in rather than queried apart: a person's page is largely a
     * list of what is left to see of them, and a work already sitting in the watchlist is
     * the most actionable line on it.
     *
     * @return list<PersonWorkDto>
     */
    private function works(Person $person, User $user): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            "SELECT
                m.id AS movie_id,
                m.title AS title,
                m.release_year AS release_year,
                m.poster_path AS poster_path,
                m.media_type AS media_type,
                ARRAY_AGG(DISTINCT c.role) AS roles,
                -- One character name even when TMDB credits them twice on the same film;
                -- the second is nearly always a variant spelling of the first.
                MIN(c.character_name) FILTER (WHERE c.role = 'actor') AS character_name,
                agg.average_rating AS my_average_rating,
                agg.last_watched_date AS last_watched_date,
                (agg.movie_id IS NOT NULL) AS watched,
                (wl.movie_id IS NOT NULL) AS in_watchlist
            FROM credit c
            JOIN movie m ON m.id = c.movie_id
            LEFT JOIN (
                SELECT w.movie_id,
                    AVG(w.rating) AS average_rating,
                    MAX(w.watched_date) FILTER (WHERE w.source <> :deducedSource) AS last_watched_date
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) agg ON agg.movie_id = m.id
            LEFT JOIN watchlist_entry wl ON wl.movie_id = m.id AND wl.user_id = :userId
            WHERE c.person_id = :personId
            GROUP BY m.id, m.title, m.release_year, m.poster_path, m.media_type,
                agg.movie_id, agg.average_rating, agg.last_watched_date, wl.movie_id
            ORDER BY m.release_year DESC NULLS LAST, m.title ASC",
            [
                'personId' => (string) $person->getId(),
                'userId' => (string) $user->getId(),
                'deducedSource' => WatchSource::CSV_RERATING->value,
            ]
        )->fetchAllAssociative();

        return array_map(fn (array $row) => new PersonWorkDto(
            movieId: (string) $row['movie_id'],
            title: (string) $row['title'],
            releaseYear: null !== $row['release_year'] ? (int) $row['release_year'] : null,
            posterUrl: null !== $row['poster_path'] ? "{$this->imageBaseUrl}/w185{$row['poster_path']}" : null,
            mediaType: MediaType::from((string) $row['media_type']),
            roles: $this->rolesOf($row['roles']),
            characterName: null !== $row['character_name'] ? (string) $row['character_name'] : null,
            myAverageRating: null !== $row['my_average_rating'] ? round((float) $row['my_average_rating'], 2) : null,
            lastWatchedDate: null !== $row['last_watched_date'] ? (string) $row['last_watched_date'] : null,
            watched: (bool) $row['watched'],
            inWatchlist: (bool) $row['in_watchlist'],
        ), $rows);
    }

    /**
     * Postgres hands an ARRAY_AGG back as the literal `{director,writer}`, which no DBAL
     * type converts on a raw query.
     *
     * @return list<CreditRole>
     */
    private function rolesOf(mixed $aggregate): array
    {
        $values = explode(',', trim((string) $aggregate, '{}'));
        $roles = array_values(array_filter(array_map(
            static fn (string $value) => CreditRole::tryFrom(trim($value, '"')),
            $values
        )));

        // Sorted on the enum's own order rather than alphabetically, so a triple credit
        // reads the way a credit block does: direction first, performance last.
        usort($roles, static fn (CreditRole $a, CreditRole $b) => array_search($a, CreditRole::cases(), true)
            <=> array_search($b, CreditRole::cases(), true));

        return $roles;
    }

    /**
     * The same works counted a second time, one bucket per job.
     *
     * Ordered by what was watched rather than by what is credited: the page leads with the
     * job this profile actually knows them for, which for a jobbing actor-director is not
     * always the one holding the most credits.
     *
     * @param list<PersonWorkDto> $works
     *
     * @return list<PersonRoleDto>
     */
    private function roles(array $works): array
    {
        /** @var array<string, array{watched: int, unwatched: int, ratings: list<float>}> $buckets */
        $buckets = [];

        foreach ($works as $work) {
            foreach ($work->roles as $role) {
                $buckets[$role->value] ??= ['watched' => 0, 'unwatched' => 0, 'ratings' => []];
                if ($work->watched) {
                    ++$buckets[$role->value]['watched'];
                    if (null !== $work->myAverageRating) {
                        $buckets[$role->value]['ratings'][] = $work->myAverageRating;
                    }
                } else {
                    ++$buckets[$role->value]['unwatched'];
                }
            }
        }

        $roles = [];
        foreach ($buckets as $value => $bucket) {
            $roles[] = new PersonRoleDto(
                role: CreditRole::from($value),
                watchedCount: $bucket['watched'],
                unwatchedCount: $bucket['unwatched'],
                averageRating: [] === $bucket['ratings']
                    ? null
                    : round(array_sum($bucket['ratings']) / \count($bucket['ratings']), 2),
            );
        }

        usort($roles, static fn (PersonRoleDto $a, PersonRoleDto $b) => [$b->watchedCount, $b->unwatchedCount]
            <=> [$a->watchedCount, $a->unwatchedCount]);

        return $roles;
    }

    /**
     * How this person is rated against everything else watched.
     *
     * Averaged per work on both sides of the subtraction. Reading the library average
     * straight off the watch rows would weigh a film watched four times four times over on
     * one side and once on the other, and the gap would say more about rewatching habits
     * than about the person.
     */
    private function gapAgainstLibrary(User $user, ?float $averageRating): ?float
    {
        if (null === $averageRating) {
            return null;
        }

        $libraryAverage = $this->entityManager->getConnection()->executeQuery(
            'SELECT AVG(per_movie) FROM (
                SELECT AVG(w.rating) AS per_movie
                FROM watch w
                WHERE w.user_id = :userId AND w.rating IS NOT NULL
                GROUP BY w.movie_id
            ) rated',
            ['userId' => (string) $user->getId()]
        )->fetchOne();

        return false === $libraryAverage || null === $libraryAverage
            ? null
            : round($averageRating - (float) $libraryAverage, 2);
    }
}
