<?php

declare(strict_types=1);

namespace App\Service\Franchise;

use App\DTO\FranchiseDto;
use App\DTO\FranchiseFilmDto;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The saga panel on a film's page: every film TMDB lists in the saga, which of them the
 * library holds, and which the profile has watched.
 *
 * One query for the whole saga rather than one per film. A saga is a handful of rows, so the
 * join back onto the library is cheap — and it is what turns a list of titles into a list of
 * links.
 */
final class FranchiseViewBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        // The same parameter MovieMapper reads, so every poster URL in one response is
        // built the same way.
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    public function build(Movie $movie, User $viewedUser): ?FranchiseDto
    {
        $franchise = $movie->getFranchise();
        if (null === $franchise) {
            return null;
        }

        $rows = $this->entityManager->getConnection()->executeQuery(
            // media_type is part of the join, not an afterthought: TMDB numbers films and
            // series in separate sequences, so a bare tmdb_id match can pair a film of the
            // saga with an entirely unrelated series that happens to hold that number.
            'SELECT
                ff.tmdb_id,
                ff.title,
                ff.release_date,
                ff.poster_path,
                m.id AS movie_id,
                EXISTS (
                    SELECT 1 FROM watch w WHERE w.movie_id = m.id AND w.user_id = :userId
                ) AS watched
            FROM franchise_film ff
            LEFT JOIN movie m ON m.tmdb_id = ff.tmdb_id AND m.media_type = :film
            WHERE ff.franchise_id = :franchiseId
            ORDER BY ff.release_date ASC NULLS LAST, ff.title ASC',
            [
                'franchiseId' => (string) $franchise->getId(),
                'userId' => (string) $viewedUser->getId(),
                'film' => MediaType::MOVIE->value,
            ]
        )->fetchAllAssociative();

        $films = [];
        $watched = 0;
        foreach ($rows as $row) {
            $isWatched = (bool) $row['watched'];
            $watched += $isWatched ? 1 : 0;

            $films[] = new FranchiseFilmDto(
                tmdbId: (int) $row['tmdb_id'],
                title: (string) $row['title'],
                releaseYear: $this->yearOf($row['release_date']),
                posterUrl: null !== $row['poster_path'] ? "{$this->imageBaseUrl}/w185{$row['poster_path']}" : null,
                movieId: null !== $row['movie_id'] ? (string) $row['movie_id'] : null,
                watched: $isWatched,
            );
        }

        return new FranchiseDto(
            id: (string) $franchise->getId(),
            name: $franchise->getName(),
            // Counted from the rows just built rather than queried separately, so the
            // headline and the list under it can never disagree.
            watchedCount: $watched,
            films: $films,
        );
    }

    private function yearOf(mixed $releaseDate): ?int
    {
        if (!\is_string($releaseDate) || '' === $releaseDate) {
            return null;
        }

        $year = (int) substr($releaseDate, 0, 4);

        return $year > 0 ? $year : null;
    }
}
