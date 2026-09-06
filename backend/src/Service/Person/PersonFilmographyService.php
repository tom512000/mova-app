<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\DTO\Person\FilmographyEntryDto;
use App\DTO\Person\FilmographyRoleDto;
use App\DTO\Person\PersonFilmographyDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Person;
use App\Entity\User;
use App\Exception\TmdbException;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "Tu en as vu 9 sur 14" — the one thing on a person's page the library cannot answer.
 *
 * Fetched on demand rather than backfilled. Sagas earned a table because the dashboard
 * aggregates them across the whole library; a filmography is only ever read one person at a
 * time, and there are 8 890 people in this database. Backfilling them all would be nearly
 * nine thousand requests to fill a page nobody may open.
 *
 * What comes back from TMDB is wide and largely worthless. `cast` files talk-show
 * appearances, archive footage and documentaries-about-the-person next to real parts — 49
 * of them for Christopher Nolan, who acts in almost nothing — and `crew` spreads a dozen
 * jobs across the Production department. Three filters cut it down to something a person
 * would recognise as their filmography:
 *
 *   - films only, because TMDB has no collection or completeness notion on /tv;
 *   - released, because a 2026 sequel is not something anybody has failed to watch;
 *   - a floor on vote count, which is what actually removes the noise. It takes Nolan's
 *     directing credits from 20 to 14 by dropping short-film compilations credited to him,
 *     and Tom Cruise's acting credits from 96 to 51 by dropping archive-footage cameos.
 *
 * The floor is a blunt instrument, and on this library it was caught cutting real work: it
 * is calibrated on an audience TMDB only gives to Anglophone releases, so recent French
 * films fall straight through it — `Regarde` sits at 43 votes and `Les Chèvres !` at 60,
 * and both are ordinary feature films that had been watched. Hence the exemption in
 * getFilmography(): a film this profile has watched is never dropped, whatever TMDB thinks
 * of it. That is not a patch over the threshold, it is the rule the threshold approximates
 * — the floor exists to guess whether an unknown credit is a real film, and there is
 * nothing left to guess about one somebody has sat through. It also makes the two counts on
 * the page incapable of contradicting each other: "20 vues" can never face a total below 20.
 */
final class PersonFilmographyService
{
    /**
     * Votes a film needs before it counts as part of a filmography.
     *
     * Chosen by measurement, not by feel: at this level Nolan comes out at 14 directing
     * credits and David Lynch at 19, which is what each of them actually made.
     */
    private const MINIMUM_VOTES = 100;

    /** Unwatched titles named per job. Beyond this the section stops being a summary. */
    private const MISSING_SHOWN = 12;

    /** A person's filmography changes a few times a decade. A week is generous. */
    private const CACHE_TTL = 604800;

    /**
     * Part of the cache key, and bumped whenever the shape stored below changes.
     *
     * Learned the hard way in development: adding the vote count to the cached rows made
     * every entry already in the pool wrong, and they had a week left to live. A deploy
     * would have handed production seven days of "Undefined array key" instead of a page.
     * Changing the key retires the old entries instead of reading them.
     */
    private const CACHE_VERSION = 2;

    /** TMDB job titles, mapped onto the roles this app records. Kept in step with TmdbMovieMapper. */
    private const JOBS = [
        'director' => ['Director'],
        'writer' => ['Writer', 'Screenplay', 'Story'],
        'producer' => ['Producer'],
    ];

    public function __construct(
        private readonly TmdbClientInterface $tmdbClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * Null when there is nothing to say: a person with no TMDB id, a TMDB that would not
     * answer, or a filmography that survives none of the filters above. The page draws
     * without the section rather than with an empty one.
     *
     * @param list<CreditRole> $roles the jobs the library credits them with, in the order
     *                                the page lists them — a filmography for a job this
     *                                profile has never seen them do is noise
     */
    public function getFilmography(Person $person, User $user, array $roles): ?PersonFilmographyDto
    {
        $tmdbId = $person->getTmdbId();
        if (null === $tmdbId) {
            return null;
        }

        try {
            $credits = $this->credits($tmdbId);
        } catch (TmdbException $exception) {
            // A person's page is worth showing without this section; failing the whole
            // request because TMDB is down would take the rest of it with it.
            $this->logger->warning('Filmographie TMDB indisponible pour {person} : {message}', [
                'person' => $person->getName(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $watched = $this->watchedTmdbIds($user, array_column($credits, 'tmdbId'));

        $sections = [];
        foreach ($roles as $role) {
            $films = array_values(array_filter(
                $credits,
                // The vote floor is applied here rather than at fetch time precisely so a
                // watched film can be exempted from it — see the class comment.
                static fn (array $credit) => $credit['role'] === $role->value
                    && ($credit['votes'] >= self::MINIMUM_VOTES || isset($watched[$credit['tmdbId']]))
            ));

            if ([] === $films) {
                continue;
            }

            $missing = array_values(array_filter(
                $films,
                static fn (array $film) => !isset($watched[$film['tmdbId']])
            ));

            $sections[] = new FilmographyRoleDto(
                role: $role,
                watchedCount: \count($films) - \count($missing),
                totalCount: \count($films),
                missing: array_map(
                    fn (array $film) => new FilmographyEntryDto(
                        tmdbId: $film['tmdbId'],
                        title: $film['title'],
                        releaseYear: $film['releaseYear'],
                        posterUrl: null !== $film['posterPath']
                            ? "{$this->imageBaseUrl}/w185{$film['posterPath']}"
                            : null,
                    ),
                    \array_slice($missing, 0, self::MISSING_SHOWN)
                ),
            );
        }

        return [] === $sections
            ? null
            : new PersonFilmographyDto(
                roles: $sections,
                note: 'Longs métrages sortis, hors apparitions d\'archive et productions confidentielles',
            );
    }

    /**
     * The person's credits, flattened to one row per film and job.
     *
     * Cached on the TMDB id alone: nothing in here depends on who is looking, so two
     * profiles opening the same page share the fetch. Which is also why the vote count is
     * carried rather than applied — the floor's exemption depends on who is looking, and a
     * cache entry filtered for one profile would be wrong for the next.
     *
     * @return list<array{tmdbId: int, title: string, releaseYear: int|null, posterPath: string|null, role: string, releaseDate: string, votes: int}>
     */
    private function credits(int $tmdbId): array
    {
        return $this->cache->get(
            'person.filmography.v'.self::CACHE_VERSION.'.'.$tmdbId,
            function (ItemInterface $item) use ($tmdbId): array {
                $item->expiresAfter(self::CACHE_TTL);

                $payload = $this->tmdbClient->getPersonCredits($tmdbId);
                $today = date('Y-m-d');

                $films = [];
                foreach ($payload['cast'] ?? [] as $credit) {
                    if ($this->countsAsFilm($credit, $today)) {
                        $films[] = $this->normalise($credit, CreditRole::ACTOR->value);
                    }
                }

                foreach ($payload['crew'] ?? [] as $credit) {
                    if (!$this->countsAsFilm($credit, $today)) {
                        continue;
                    }
                    foreach (self::JOBS as $role => $jobs) {
                        if (\in_array($credit['job'] ?? '', $jobs, true)) {
                            $films[] = $this->normalise($credit, $role);
                        }
                    }
                }

                // TMDB credits the same person twice on the same film often enough to
                // matter — a writer filed under both Writer and Screenplay, a co-producer
                // listed by two departments — and each duplicate would inflate the total
                // this section exists to state.
                $unique = [];
                foreach ($films as $film) {
                    $unique[$film['role'].':'.$film['tmdbId']] = $film;
                }

                $films = array_values($unique);
                usort($films, static fn (array $a, array $b) => $b['releaseDate'] <=> $a['releaseDate']);

                return $films;
            }
        );
    }

    /**
     * The two filters that need nobody's viewing history to decide: it is a film, and it
     * has come out. The vote floor is not among them — see credits().
     *
     * @param array<string, mixed> $credit
     */
    private function countsAsFilm(array $credit, string $today): bool
    {
        $releaseDate = (string) ($credit['release_date'] ?? '');

        return 'movie' === ($credit['media_type'] ?? '')
            && '' !== $releaseDate
            && $releaseDate <= $today;
    }

    /**
     * @param array<string, mixed> $credit
     *
     * @return array{tmdbId: int, title: string, releaseYear: int|null, posterPath: string|null, role: string, releaseDate: string, votes: int}
     */
    private function normalise(array $credit, string $role): array
    {
        $releaseDate = (string) ($credit['release_date'] ?? '');

        return [
            'tmdbId' => (int) $credit['id'],
            'title' => (string) ($credit['title'] ?? $credit['original_title'] ?? '—'),
            'releaseYear' => '' !== $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
            'posterPath' => ('' !== ($credit['poster_path'] ?? '')) ? (string) $credit['poster_path'] : null,
            'role' => $role,
            'releaseDate' => $releaseDate,
            'votes' => (int) ($credit['vote_count'] ?? 0),
        ];
    }

    /**
     * Which of those films this profile has watched, matched on TMDB id.
     *
     * On the TMDB id and not on the credit table, which would undercount badly. Only the
     * first fifteen billed actors of a work are imported, so an actor sixteenth on the call
     * sheet holds no credit row for a film that is nonetheless in the library and watched.
     * Counting it as missing would tell somebody to go and see a film they have already
     * seen — the same trap the saga block avoids by joining on tmdb_id.
     *
     * @param list<int> $tmdbIds
     *
     * @return array<int, true>
     */
    private function watchedTmdbIds(User $user, array $tmdbIds): array
    {
        $tmdbIds = array_values(array_unique($tmdbIds));
        if ([] === $tmdbIds) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT DISTINCT m.tmdb_id
            FROM movie m
            JOIN watch w ON w.movie_id = m.id AND w.user_id = :userId
            WHERE m.tmdb_id IN (:tmdbIds) AND m.media_type = :film',
            [
                'userId' => (string) $user->getId(),
                'tmdbIds' => $tmdbIds,
                // TMDB numbers films and series in independent sequences, so a series
                // sharing an id with one of these films would otherwise count as watching it.
                'film' => MediaType::MOVIE->value,
            ],
            ['tmdbIds' => ArrayParameterType::INTEGER]
        )->fetchFirstColumn();

        return array_fill_keys(array_map('intval', $rows), true);
    }
}
