<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\CreditDto;
use App\DTO\MovieDetailDto;
use App\DTO\MoviePosterDto;
use App\DTO\MovieSummaryDto;
use App\DTO\WatchDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Stats\StatsMath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MovieMapper
{
    public function __construct(
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * Straight from a raw row rather than an entity: the museum wall asks for the whole
     * library at once, and hydrating seven hundred Movies to read four columns off each
     * would be the slowest way to draw a picture.
     *
     * @param array<string, mixed> $row from MovieRepository::posterWall()
     */
    public function toPosterDto(array $row): MoviePosterDto
    {
        return new MoviePosterDto(
            id: (string) $row['id'],
            title: (string) $row['title'],
            releaseYear: null !== $row['release_year'] ? (int) $row['release_year'] : null,
            // w185 rather than the w342 a card gets: a wall holds dozens at once and they
            // are never shown much wider than a thumbnail.
            posterUrl: "{$this->imageBaseUrl}/w185{$row['poster_path']}",
            myAverageRating: null !== $row['average_rating'] ? round((float) $row['average_rating'], 2) : null,
            mediaType: MediaType::from((string) $row['media_type']),
        );
    }

    public function toSummaryDto(Movie $movie, User $viewedUser): MovieSummaryDto
    {
        // Movie::getWatches() spans every account that ever logged this film, since the
        // Movie row is shared TMDB catalogue. Filtering to the viewed profile here is what
        // stops one user's ratings and rewatch counts showing up on another's card.
        $watches = $this->watchesOf($movie, $viewedUser);

        $ratings = array_values(array_filter(array_map(
            static fn (Watch $w) => $w->getRating(),
            $watches
        )));

        return new MovieSummaryDto(
            id: (string) $movie->getId(),
            title: $movie->getTitle(),
            releaseYear: $movie->getReleaseYear(),
            posterUrl: $this->posterUrl($movie->getPosterPath()),
            myAverageRating: StatsMath::mean($ratings),
            watchCount: \count($watches),
            runtimeMinutes: $movie->getRuntimeMinutes(),
            enrichmentStatus: $movie->getEnrichmentStatus(),
            mediaType: $movie->getMediaType(),
        );
    }

    /**
     * @return list<Watch>
     */
    private function watchesOf(Movie $movie, User $viewedUser): array
    {
        return array_values(array_filter(
            $movie->getWatches()->toArray(),
            // equals(), never ===: two Uuid objects carrying the same value are
            // different instances, so === would quietly match nothing at all.
            static fn (Watch $w) => $w->getUser()->getId()->equals($viewedUser->getId())
        ));
    }

    public function toDetailDto(Movie $movie, User $viewedUser): MovieDetailDto
    {
        $directors = [];
        $creators = [];
        $cast = [];
        foreach ($movie->getCredits() as $credit) {
            $dto = new CreditDto(
                personId: (string) $credit->getPerson()->getId(),
                name: $credit->getPerson()->getName(),
                profileUrl: $this->profileUrl($credit->getPerson()->getProfilePath()),
                characterName: $credit->getCharacterName(),
            );

            if (CreditRole::DIRECTOR === $credit->getRole()) {
                $directors[] = $dto;
            } elseif (CreditRole::CREATOR === $credit->getRole()) {
                $creators[] = $dto;
            } elseif (CreditRole::ACTOR === $credit->getRole()) {
                $cast[] = $dto;
            }
        }

        $watches = array_map(
            static fn (Watch $w) => new WatchDto(
                id: (string) $w->getId(),
                watchedDate: $w->getWatchedDate()?->format('Y-m-d'),
                rating: $w->getRating(),
                isRewatch: $w->isRewatch(),
                isDeduced: $w->getSource()->isDeduced(),
                reviewText: $w->getReviewText(),
                containsSpoilers: $w->hasSpoilers(),
                tags: array_map(static fn ($t) => $t->getName(), $w->getTags()->toArray()),
            ),
            $this->watchesOf($movie, $viewedUser)
        );
        usort($watches, static fn (WatchDto $a, WatchDto $b) => ($a->watchedDate ?? '') <=> ($b->watchedDate ?? ''));

        return new MovieDetailDto(
            id: (string) $movie->getId(),
            title: $movie->getTitle(),
            originalTitle: $movie->getOriginalTitle(),
            releaseYear: $movie->getReleaseYear(),
            runtimeMinutes: $movie->getRuntimeMinutes(),
            synopsis: $movie->getSynopsis(),
            posterUrl: $this->posterUrl($movie->getPosterPath()),
            backdropUrl: $this->backdropUrl($movie->getBackdropPath()),
            tmdbVoteAverage: $movie->getTmdbVoteAverage(),
            imdbId: $movie->getImdbId(),
            enrichmentStatus: $movie->getEnrichmentStatus(),
            genres: array_map(static fn ($g) => $g->getName(), $movie->getGenres()->toArray()),
            countries: array_map(static fn ($c) => $c->getName(), $movie->getCountries()->toArray()),
            directors: $directors,
            creators: $creators,
            cast: $cast,
            watches: $watches,
            mediaType: $movie->getMediaType(),
            seasonCount: $movie->getSeasonCount(),
            episodeCount: $movie->getEpisodeCount(),
            lastAirDate: $movie->getLastAirDate()?->format('Y-m-d'),
        );
    }

    private function posterUrl(?string $path): ?string
    {
        return null !== $path ? "{$this->imageBaseUrl}/w342{$path}" : null;
    }

    private function backdropUrl(?string $path): ?string
    {
        return null !== $path ? "{$this->imageBaseUrl}/w1280{$path}" : null;
    }

    private function profileUrl(?string $path): ?string
    {
        return null !== $path ? "{$this->imageBaseUrl}/w185{$path}" : null;
    }
}
