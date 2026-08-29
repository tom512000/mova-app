<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\Profile\FavouriteFilmDto;
use App\DTO\Profile\LetterboxdProfileDto;
use App\Entity\FavouriteFilm;
use App\Entity\LetterboxdProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LetterboxdProfileMapper
{
    public function __construct(
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    public function toDto(LetterboxdProfile $profile): LetterboxdProfileDto
    {
        $favourites = [];
        foreach ($profile->getFavourites() as $favourite) {
            $favourites[] = $this->favouriteToDto($favourite);
        }

        return new LetterboxdProfileDto(
            username: $profile->getUsername(),
            fullName: $profile->getFullName(),
            location: $profile->getLocation(),
            website: $profile->getWebsite(),
            bio: $profile->getBio(),
            pronoun: $profile->getPronoun(),
            joinedOn: $profile->getJoinedOn()?->format('Y-m-d'),
            favourites: $favourites,
            importedAt: $profile->getImportedAt()->format('c'),
        );
    }

    private function favouriteToDto(FavouriteFilm $favourite): FavouriteFilmDto
    {
        $movie = $favourite->getMovie();
        $posterPath = $movie->getPosterPath();

        return new FavouriteFilmDto(
            movieId: (string) $movie->getId(),
            title: $movie->getTitle(),
            releaseYear: $movie->getReleaseYear(),
            // w342 like the rest of the app's cards: the four are drawn at poster size, not
            // as thumbnails.
            posterUrl: null !== $posterPath ? "{$this->imageBaseUrl}/w342{$posterPath}" : null,
            position: $favourite->getPosition(),
        );
    }
}
