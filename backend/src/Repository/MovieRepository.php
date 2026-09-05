<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\MovieFacetsDto;
use App\DTO\MovieSearchCriteria;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Enum\GameKind;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\MovieSortField;
use App\Entity\Movie;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Movie>
 */
class MovieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Movie::class);
    }

    public function findOneByLetterboxdSlug(string $slug): ?Movie
    {
        return $this->findOneBy(['letterboxdSlug' => $slug]);
    }

    public function findOneByTmdbId(int $tmdbId): ?Movie
    {
        return $this->findOneBy(['tmdbId' => $tmdbId]);
    }

    /**
     * Sorting on a per-profile average forces the aggregate into the query itself, which DQL
     * cannot express in an ORDER BY — hence raw SQL for the page's ids, then one hydration
     * pass for the entities.
     *
     * @return array{items: list<Movie>, total: int}
     */
    public function search(User $user, MovieSearchCriteria $criteria): array
    {
        $params = ['userId' => (string) $user->getId()];
        $conditions = [];

        if (null !== $criteria->query && '' !== $criteria->query) {
            $conditions[] = '(LOWER(m.title) LIKE :query OR LOWER(m.original_title) LIKE :query)';
            // A % or _ typed in the search box is a literal character, not a wildcard.
            $params['query'] = '%'.str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\%', '\_'],
                mb_strtolower($criteria->query)
            ).'%';
        }

        if (null !== $criteria->genre && '' !== $criteria->genre) {
            $conditions[] = 'EXISTS (SELECT 1 FROM movie_genre mg JOIN genre g ON g.id = mg.genre_id WHERE mg.movie_id = m.id AND g.name = :genre)';
            $params['genre'] = $criteria->genre;
        }

        if (null !== $criteria->year) {
            $conditions[] = 'm.release_year = :year';
            $params['year'] = $criteria->year;
        }

        if ($criteria->unratedOnly) {
            $conditions[] = 'agg.average_rating IS NULL';
        } elseif (null !== $criteria->rating) {
            // The card shows an average, but the filter answers "did I ever give it that
            // note?" — a rewatch scored differently belongs under both of its notes.
            $conditions[] = 'EXISTS (SELECT 1 FROM watch wr WHERE wr.movie_id = m.id AND wr.user_id = :userId AND wr.rating = :rating)';
            $params['rating'] = number_format($criteria->rating, 1, '.', '');
        }

        if (null !== $criteria->watchedOn) {
            // One row of the calendar square, not the film's own aggregate: a rewatch is
            // what puts a film under a second date, and the square counted that rewatch.
            $conditions[] = 'EXISTS (SELECT 1 FROM watch wd WHERE wd.movie_id = m.id AND wd.user_id = :userId AND wd.watched_date = :watchedOn)';
            $params['watchedOn'] = $criteria->watchedOn;
        }

        if (null !== $criteria->mediaType) {
            $conditions[] = 'm.media_type = :mediaType';
            $params['mediaType'] = $criteria->mediaType->value;
        }

        if (null !== $criteria->personId) {
            $credit = 'EXISTS (SELECT 1 FROM credit c WHERE c.movie_id = m.id AND c.person_id = :personId';
            $params['personId'] = $criteria->personId;
            if (null !== $criteria->personRole) {
                $credit .= ' AND c.role = :personRole';
                $params['personRole'] = $criteria->personRole->value;
            }
            $conditions[] = $credit.')';
        }

        if (null !== $criteria->studioId) {
            // EXISTS rather than a join: a film carries several studios, and joining would
            // return it once per matching row.
            $conditions[] = 'EXISTS (SELECT 1 FROM movie_studio ms WHERE ms.movie_id = m.id AND ms.studio_id = :studioId)';
            $params['studioId'] = $criteria->studioId;
        }

        $from = $this->watchedByProfile();
        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);

        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery("SELECT COUNT(*) {$from}{$where}", $params)->fetchOne();

        $pageParams = $params;
        if (MovieSortField::RANDOM === $criteria->sort) {
            $pageParams['seed'] = $criteria->seed ?? '';
        }
        $pageParams['limit'] = $criteria->perPage;
        $pageParams['offset'] = $criteria->offset();

        $ids = $connection->executeQuery(
            "SELECT m.id {$from}{$where} ORDER BY {$this->orderBy($criteria)} LIMIT :limit OFFSET :offset",
            $pageParams,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return [
            'items' => $this->findByIdsOrdered(array_map('strval', $ids)),
            'total' => $total,
        ];
    }

    /**
     * One film the profile could reasonably be asked to guess: watched, and carrying whatever
     * the game in question needs to be playable at all — which is not the same thing from one
     * game to the next, hence the split below. Ordering by a hash of the seed makes the pick
     * reproducible: the daily puzzle needs the same answer all day, and a test needs to know
     * what it will get.
     *
     * Series are never drawn. They live in the same table behind a media_type discriminator,
     * so without the filter "Le film mystère" would happily serve WandaVision — a title the
     * clue ladder describes badly (a season count is not a runtime) and the hangman hides
     * under a heading that promises a film. The same exclusion applies to the pair and set
     * draws below.
     *
     * @param list<string> $excludeIds recent answers, so the infinite mode stops repeating itself
     */
    public function findGuessable(User $user, GameKind $game, string $seed, array $excludeIds = []): ?Movie
    {
        $params = ['userId' => (string) $user->getId(), 'seed' => $seed, 'mediaType' => MediaType::MOVIE->value];
        $types = [];

        if (GameKind::POSTER === $game) {
            // The artwork is the entire game, so it is the entire requirement: a film with
            // no year, no credits and no studio is perfectly playable here.
            $playable = 'AND m.poster_path IS NOT NULL';
        } elseif (GameKind::BACKDROP === $game) {
            $playable = 'AND m.backdrop_path IS NOT NULL';
        } elseif (GameKind::HANGMAN === $game) {
            // Enough letters to be worth masking. "Ted" and "Rio" are solved by their own
            // shape, and a title made only of digits ("1917") would come up already won.
            $playable = "AND char_length(regexp_replace(m.title, '[^[:alpha:]]', '', 'g')) >= 4";
        } else {
            // The clue ladder and the comparison card walk the same attributes, so an answer
            // missing one of them would leave a rung blank or a tile grey for the wrong
            // reason. This list has to keep mirroring FilmClueBuilder.
            $playable = 'AND m.release_year IS NOT NULL
                AND EXISTS (SELECT 1 FROM movie_genre mg WHERE mg.movie_id = m.id)
                AND EXISTS (SELECT 1 FROM movie_country mc WHERE mc.movie_id = m.id)
                AND EXISTS (SELECT 1 FROM credit cd WHERE cd.movie_id = m.id AND cd.role = :director)
                AND (SELECT COUNT(*) FROM credit ca WHERE ca.movie_id = m.id AND ca.role = :actor) >= 3';
            $params['director'] = CreditRole::DIRECTOR->value;
            $params['actor'] = CreditRole::ACTOR->value;

            if (GameKind::TAGLINE === $game) {
                // The tagline is the opening card and the clue ladder is what unlocks under
                // it once the player misses, so both sets of requirements have to hold. TMDB
                // records "no tagline" as an empty string about as often as it records null.
                $playable .= " AND m.tagline IS NOT NULL AND m.tagline <> ''";
            }
        }

        $exclusion = '';
        if ([] !== $excludeIds) {
            $exclusion = ' AND m.id NOT IN (:excluded)';
            $params['excluded'] = $excludeIds;
            $types['excluded'] = ArrayParameterType::STRING;
        }

        $id = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT m.id
            FROM movie m
            WHERE EXISTS (SELECT 1 FROM watch w WHERE w.movie_id = m.id AND w.user_id = :userId)
                AND m.media_type = :mediaType
                '.$playable
            .$exclusion.'
            ORDER BY md5(:seed || m.id::text)
            LIMIT 1',
            $params,
            $types
        )->fetchOne();

        return false === $id || null === $id ? null : $this->find((string) $id);
    }

    /**
     * Two watched films the profile rated differently — the duel's board.
     *
     * Drawn in two passes rather than as one seeded pick over every possible pair: the cross
     * join is 700² rows to hash for a result of one, and a fresh pair is drawn on every
     * correct answer. The first film is picked freely, the second from whatever is left
     * carrying a different average.
     *
     * The gap between the two is deliberately not bounded. Capping it would make every round
     * a near coin-flip and kill most streaks at two; leaving it open gives the mix a streak
     * game needs — easy rounds to build on, close ones to die on.
     *
     * @param list<string> $excludeIds films already played in this run
     *
     * @return list<Movie> exactly two, or empty when the library cannot field a pair
     */
    public function findDuelPair(User $user, string $seed, array $excludeIds = []): array
    {
        $connection = $this->getEntityManager()->getConnection();

        // Averaged rather than taken from one watch: a film seen twice and rated differently
        // has one standing verdict, and it is the same number the rest of the app shows.
        $rated = 'SELECT m.id, AVG(w.rating) AS rating
            FROM movie m
            JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
            WHERE m.media_type = :mediaType
                AND m.poster_path IS NOT NULL
                AND w.rating IS NOT NULL
            GROUP BY m.id';

        $params = [
            'userId' => (string) $user->getId(),
            'mediaType' => MediaType::MOVIE->value,
            'seed' => $seed,
        ];
        $types = [];

        $filter = '';
        if ([] !== $excludeIds) {
            $filter = ' AND r.id NOT IN (:excluded)';
            $params['excluded'] = $excludeIds;
            $types['excluded'] = ArrayParameterType::STRING;
        }

        $first = $connection->executeQuery(
            "WITH r AS ({$rated})
            SELECT r.id, r.rating FROM r
            WHERE true{$filter}
            ORDER BY md5(:seed || r.id::text)
            LIMIT 1",
            $params,
            $types
        )->fetchAssociative();

        if (false === $first) {
            return [];
        }

        // A seed of its own for the second draw: reusing the first would order both queries
        // by the same hash and hand back the same neighbours every round.
        $params['seed'] = $seed.'-opponent';
        $params['first'] = $first['id'];
        $params['rating'] = $first['rating'];

        $second = $connection->executeQuery(
            "WITH r AS ({$rated})
            SELECT r.id FROM r
            WHERE r.id <> :first AND r.rating <> :rating{$filter}
            ORDER BY md5(:seed || r.id::text)
            LIMIT 1",
            $params,
            $types
        )->fetchOne();

        if (false === $second || null === $second) {
            return [];
        }

        return $this->findByIdsOrdered([(string) $first['id'], (string) $second]);
    }

    /**
     * Watched films with distinct release years — the timeline's board.
     *
     * The distinct years are the point: two films sharing one would leave the puzzle with no
     * single right answer, and the player would be marked wrong for an ordering just as true
     * as the stored one. DISTINCT ON collapses each year to one seeded film first, and the
     * board is drawn from those survivors.
     *
     * Artwork is not required, unlike in the duel: this game is about years, and a film with
     * no poster is just as orderable as one with a poster. Its card falls back to its title.
     *
     * @return list<Movie> exactly $size of them, or empty when the library is too thin
     */
    public function findTimelineSet(User $user, string $seed, int $size): array
    {
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            'WITH one_per_year AS (
                SELECT DISTINCT ON (m.release_year) m.id
                FROM movie m
                WHERE m.media_type = :mediaType
                    AND m.release_year IS NOT NULL
                    AND EXISTS (SELECT 1 FROM watch w WHERE w.movie_id = m.id AND w.user_id = :userId)
                ORDER BY m.release_year, md5(:seed || m.id::text)
            )
            SELECT id FROM one_per_year
            ORDER BY md5(:seed || id::text)
            LIMIT :size',
            [
                'userId' => (string) $user->getId(),
                'mediaType' => MediaType::MOVIE->value,
                'seed' => $seed,
                'size' => $size,
            ],
            ['size' => ParameterType::INTEGER]
        )->fetchFirstColumn();

        return \count($ids) < $size ? [] : $this->findByIdsOrdered(array_map('strval', $ids));
    }

    public function facetsFor(User $user): MovieFacetsDto
    {
        $connection = $this->getEntityManager()->getConnection();
        $params = ['userId' => (string) $user->getId()];

        $genres = $connection->executeQuery(
            'SELECT DISTINCT g.name
            FROM genre g
            JOIN movie_genre mg ON mg.genre_id = g.id
            JOIN watch w ON w.movie_id = mg.movie_id AND w.user_id = :userId
            ORDER BY g.name',
            $params
        )->fetchFirstColumn();

        $years = $connection->executeQuery(
            'SELECT DISTINCT m.release_year
            FROM movie m
            JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
            WHERE m.release_year IS NOT NULL
            ORDER BY m.release_year DESC',
            $params
        )->fetchFirstColumn();

        $ratings = $connection->executeQuery(
            'SELECT DISTINCT w.rating
            FROM watch w
            WHERE w.user_id = :userId AND w.rating IS NOT NULL
            ORDER BY w.rating DESC',
            $params
        )->fetchFirstColumn();

        // COUNT over a nullable column skips nulls, so a zero means the film carries no
        // note on any of its watches.
        $hasUnrated = (bool) $connection->executeQuery(
            'SELECT EXISTS (
                SELECT 1 FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
                HAVING COUNT(w.rating) = 0
            )',
            $params
        )->fetchOne();

        return new MovieFacetsDto(
            genres: array_values(array_map('strval', $genres)),
            years: array_values(array_map('intval', $years)),
            ratings: array_values(array_map('floatval', $ratings)),
            hasUnrated: $hasUnrated,
        );
    }

    /**
     * Every poster in the profile's library, in one shot and without hydrating a single
     * entity: the museum wall shows all of them at once, so paging it would only mean the
     * wall could not be scrolled past its first screen.
     *
     * @return list<array<string, mixed>>
     */
    public function posterWall(User $user, MovieSearchCriteria $criteria): array
    {
        $params = ['userId' => (string) $user->getId()];
        if (MovieSortField::RANDOM === $criteria->sort) {
            $params['seed'] = $criteria->seed ?? '';
        }

        $kind = '';
        if (null !== $criteria->mediaType) {
            $kind = ' AND m.media_type = :mediaType';
            $params['mediaType'] = $criteria->mediaType->value;
        }

        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT m.id, m.title, m.release_year, m.poster_path, m.media_type, agg.average_rating '
            .$this->watchedByProfile()
            .' WHERE m.poster_path IS NOT NULL'
            .$kind
            ." ORDER BY {$this->orderBy($criteria)}",
            $params
        )->fetchAllAssociative();
    }

    /**
     * The catalogue narrowed to what this profile has watched, with its per-film aggregates.
     * The join is what does the narrowing, which is why it is never a LEFT one.
     */
    private function watchedByProfile(): string
    {
        return 'FROM movie m
            JOIN (
                SELECT w.movie_id,
                    AVG(w.rating) AS average_rating,
                    MAX(w.watched_date) AS last_watched_date,
                    MAX(w.created_at) AS added_at
                FROM watch w
                WHERE w.user_id = :userId
                GROUP BY w.movie_id
            ) agg ON agg.movie_id = m.id';
    }

    /**
     * Never interpolates user input: the direction comes from a bool and the columns from a
     * closed enum. Films with nothing to sort on stay at the bottom whichever way the sort
     * points, which is why NULLS LAST is spelled out rather than left to the SQL default.
     */
    private function orderBy(MovieSearchCriteria $criteria): string
    {
        $direction = $criteria->descending ? 'DESC' : 'ASC';
        $tieBreak = 'LOWER(m.title) ASC, m.id ASC';

        return match ($criteria->sort) {
            MovieSortField::TITLE => "LOWER(m.title) {$direction}, m.id ASC",
            MovieSortField::RATING => "agg.average_rating {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::YEAR => "m.release_year {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::WATCHED => "agg.last_watched_date {$direction} NULLS LAST, {$tieBreak}",
            MovieSortField::ADDED => "agg.added_at {$direction}, {$tieBreak}",
            MovieSortField::RUNTIME => "m.runtime_minutes {$direction} NULLS LAST, {$tieBreak}",
            // Hashing the seed together with the id gives each seed its own stable
            // permutation, so paging through a shuffle neither repeats nor skips a film.
            MovieSortField::RANDOM => 'md5(:seed || m.id::text) ASC',
        };
    }

    /**
     * Hydrates a list of ids while keeping the order it was given in — every draw above
     * chooses an order in SQL, and a plain findBy() would throw it away.
     *
     * @param list<string> $ids UUIDs, in the order they should come back
     *
     * @return list<Movie>
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // addSelect('w') pulls the whole page's watches in this one query — MovieMapper
        // narrows them to the viewed profile afterwards, and without it every card would
        // lazy-load its own collection.
        $movies = $this->createQueryBuilder('m')
            ->addSelect('w')
            ->leftJoin('m.watches', 'w')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($movies as $movie) {
            // Cast: a Uuid object cannot be used as an array key.
            $byId[(string) $movie->getId()] = $movie;
        }

        // SQL gave the order; the hydration above did not preserve it.
        return array_values(array_filter(array_map(
            static fn (string $id) => $byId[$id] ?? null,
            $ids
        )));
    }

    /**
     * Movies TmdbResolver already matched and closed as ENRICHED. They are never revisited
     * by findNeedingEnrichment(), so a wrong-but-confident match stays wrong forever unless
     * something re-checks it — see app:tmdb:audit-matches.
     *
     * @return Movie[]
     */
    public function findEnrichedForAudit(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.enrichmentStatus = :status')
            ->andWhere('m.tmdbId IS NOT NULL')
            ->setParameter('status', EnrichmentStatus::ENRICHED)
            ->orderBy('m.id', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Narrows a list of ids to the films an enrichment job could still do something with.
     *
     * An import hands back every film it touched, which on a re-import — or on a second
     * account importing the same export — is overwhelmingly films the library already knows
     * everything about. Queueing those was never wrong: EnrichMovieMessageHandler returns
     * immediately on an ENRICHED film without going near TMDB. It was just expensive for
     * nothing: measured at roughly 5 ms to write the message and 2 ms to consume it, seven
     * hundred films came to about five seconds of pure queue churn per file, repeated for
     * every file in the zip that mentions them.
     *
     * Ids come back in the order they were given, so enrichment still follows the order of
     * the CSV rather than whatever the planner felt like.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function filterNeedingEnrichment(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Only the ids are selected: hydrating whole entities to read one enum off each is
        // exactly the work this method exists to avoid.
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.id IN (:ids)')
            ->andWhere('m.enrichmentStatus IN (:statuses)')
            ->setParameter('ids', $ids)
            ->setParameter('statuses', EnrichmentStatus::needingEnrichment())
            ->getQuery()
            ->getScalarResult();

        $needing = [];
        foreach ($rows as $row) {
            // Cast: depending on hydration this is a Uuid object rather than a string, and
            // either way it has to become an array key.
            $needing[(string) $row['id']] = true;
        }

        return array_values(array_filter($ids, static fn (string $id) => isset($needing[$id])));
    }

    /**
     * @return Movie[]
     */
    public function findNeedingEnrichment(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.enrichmentStatus IN (:statuses)')
            ->setParameter('statuses', [EnrichmentStatus::PENDING, EnrichmentStatus::FAILED, EnrichmentStatus::AMBIGUOUS])
            ->orderBy('m.lastEnrichmentAttemptAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
