<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\CreditDto;
use App\DTO\MovieDetailDto;
use App\DTO\MovieSummaryDto;
use App\DTO\WatchDto;
use App\Entity\Enum\CreditRole;
use App\Entity\Movie;
use App\Service\Stats\StatsMath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MovieMapper
{
    public function __construct(
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    public function toSummaryDto(Movie $movie): MovieSummaryDto
    {
        $ratings = array_values(array_filter(array_map(
            static fn ($w) => $w->getRating(),
            $movie->getWatches()->toArray()
        )));

        return new MovieSummaryDto(
            id: $movie->getId(),
            title: $movie->getTitle(),
            releaseYear: $movie->getReleaseYear(),
            posterUrl: $this->posterUrl($movie->getPosterPath()),
            myAverageRating: StatsMath::mean($ratings),
            watchCount: $movie->getWatches()->count(),
            enrichmentStatus: $movie->getEnrichmentStatus(),
        );
    }

    public function toDetailDto(Movie $movie): MovieDetailDto
    {
        $directors = [];
        $cast = [];
        foreach ($movie->getCredits() as $credit) {
            $dto = new CreditDto(
                personId: $credit->getPerson()->getId(),
                name: $credit->getPerson()->getName(),
                profileUrl: $this->profileUrl($credit->getPerson()->getProfilePath()),
                characterName: $credit->getCharacterName(),
            );

            if (CreditRole::DIRECTOR === $credit->getRole()) {
                $directors[] = $dto;
            } elseif (CreditRole::ACTOR === $credit->getRole()) {
                $cast[] = $dto;
            }
        }

        $watches = array_map(
            static fn ($w) => new WatchDto(
                id: $w->getId(),
                watchedDate: $w->getWatchedDate()?->format('Y-m-d'),
                rating: $w->getRating(),
                isRewatch: $w->isRewatch(),
                reviewText: $w->getReviewText(),
                containsSpoilers: $w->hasSpoilers(),
                tags: array_map(static fn ($t) => $t->getName(), $w->getTags()->toArray()),
            ),
            $movie->getWatches()->toArray()
        );
        usort($watches, static fn (WatchDto $a, WatchDto $b) => ($a->watchedDate ?? '') <=> ($b->watchedDate ?? ''));

        return new MovieDetailDto(
            id: $movie->getId(),
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
            cast: $cast,
            watches: $watches,
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
