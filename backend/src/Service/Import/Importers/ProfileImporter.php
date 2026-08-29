<?php

declare(strict_types=1);

namespace App\Service\Import\Importers;

use App\Entity\Enum\ImportFileType;
use App\Entity\FavouriteFilm;
use App\Entity\ImportBatch;
use App\Entity\ImportRowError;
use App\Entity\LetterboxdProfile;
use App\Entity\Movie;
use App\Repository\LetterboxdProfileRepository;
use App\Service\Import\CsvReader;
use App\Service\Import\FilmSlugResolver;
use App\Service\Import\ImporterInterface;
use App\Service\Import\MovieUpserter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * profile.csv: who the account belongs to, and the four films pinned to the top of it.
 *
 * The only importer that does not extend AbstractCsvImporter, because it does not fit its
 * shape. That base class exists for the four film lists, where a row *is* a film and the
 * counters count films. Here the file is exactly one row describing a person, and its one
 * interesting column holds four films at once. Forcing it through importRow(): ?Movie would
 * mean returning one of the four and smuggling the rest out sideways.
 *
 * Observed columns: Date Joined, Username, Given Name, Family Name, Email Address, Location,
 * Website, Bio, Pronoun, Favorite Films. Every one of them can be blank — Letterboxd asks
 * for none of them — so nothing here is required except the row itself.
 *
 * Email Address is read and dropped on purpose: it is already the address this account signs
 * in with, and a second copy of somebody's email that no screen displays is a liability
 * rather than a feature.
 */
final class ProfileImporter implements ImporterInterface
{
    /** Letterboxd offers four slots and the export lists them in order. */
    private const MAX_FAVOURITES = 4;

    public function __construct(
        private readonly CsvReader $csvReader,
        private readonly FilmSlugResolver $slugResolver,
        private readonly MovieUpserter $movieUpserter,
        private readonly LetterboxdProfileRepository $profiles,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFileType(): ImportFileType
    {
        return ImportFileType::PROFILE;
    }

    public function supports(string $filename, array $header): bool
    {
        // Filename-exact, like every other importer here: column-shape heuristics have
        // proven unreliable against a real export.
        return 'profile.csv' === strtolower($filename);
    }

    /**
     * @return list<string>
     */
    public function import(string $filepath, ImportBatch $batch): array
    {
        $this->movieUpserter->reset();

        $user = $batch->getUser();
        $profile = $this->profiles->findOneByUser($user);

        if (null === $profile) {
            $profile = new LetterboxdProfile($user);
            $this->entityManager->persist($profile);
        } else {
            $profile->touchImportedAt();
        }

        $touched = [];

        foreach ($this->csvReader->readAssoc($filepath) as $rowNumber => $row) {
            $this->fill($profile, $row);

            // Replaced wholesale rather than reconciled: see LetterboxdProfile::clearFavourites.
            $profile->clearFavourites();

            // The emptying gets a flush of its own. Slots are unique per profile, and within
            // one flush Doctrine runs inserts before deletes — so a re-import whose first
            // favourite claims slot 1 would collide with last time's slot 1, which has not
            // been deleted yet. Sending the deletes first costs one statement and removes the
            // whole class of collision.
            $this->entityManager->flush();

            $position = 0;
            foreach ($this->favouriteLinks($row) as $link) {
                $movie = $this->resolve($link);

                if (null === $movie) {
                    // One unreachable short link must not cost the whole profile. It is
                    // recorded against the batch and the remaining slots still fill.
                    $this->recordError($batch, $rowNumber, $row, $link);
                    continue;
                }

                $favourite = new FavouriteFilm($profile, $movie, ++$position);
                $profile->addFavourite($favourite);
                $this->entityManager->persist($favourite);

                $touched[spl_object_id($movie)] = $movie;
            }

            $batch->incrementRowsImported();
        }

        $this->entityManager->flush();

        return array_values(array_unique(array_map(static fn (Movie $m) => (string) $m->getId(), $touched)));
    }

    /**
     * @param array<string, string|null> $row
     */
    private function fill(LetterboxdProfile $profile, array $row): void
    {
        $profile
            ->setUsername($this->optional($row, 'Username'))
            ->setGivenName($this->optional($row, 'Given Name'))
            ->setFamilyName($this->optional($row, 'Family Name'))
            ->setLocation($this->optional($row, 'Location'))
            ->setWebsite($this->optional($row, 'Website'))
            ->setBio($this->optional($row, 'Bio'))
            ->setPronoun($this->optional($row, 'Pronoun'))
            ->setJoinedOn($this->optionalDate($row, 'Date Joined'));
    }

    /**
     * The short links in "Favorite Films", which the export writes as one comma-separated
     * cell. Capped at four so a future Letterboxd change cannot quietly turn the profile
     * block into a second watchlist.
     *
     * @param array<string, string|null> $row
     *
     * @return list<string>
     */
    private function favouriteLinks(array $row): array
    {
        $cell = trim((string) ($row['Favorite Films'] ?? ''));
        if ('' === $cell) {
            return [];
        }

        $links = array_values(array_filter(array_map('trim', explode(',', $cell))));

        return \array_slice($links, 0, self::MAX_FAVOURITES);
    }

    /**
     * The film behind a boxd.it link, created as a stub if the library has never seen it.
     *
     * A favourite is nearly always a film already imported from ratings.csv or watched.csv,
     * and MovieUpserter finds it by slug — in which case the title passed here is ignored.
     * The fallback only matters for a favourite the account never logged: the slug becomes a
     * readable placeholder until TMDB enrichment replaces it with the real title.
     */
    private function resolve(string $link): ?Movie
    {
        $slug = $this->slugResolver->resolve($link);
        if (null === $slug) {
            return null;
        }

        return $this->movieUpserter->upsert($slug, ucwords(str_replace('-', ' ', $slug)), null);
    }

    /**
     * Notes a favourite that could not be resolved, without counting it as a failed row.
     *
     * The row did not fail: the profile was read and saved, and the other slots filled. And
     * the file is a single row, so charging a lost link to rowsFailed would leave a one-line
     * import claiming one line imported *and* one failed. The error is recorded so it shows
     * up in the batch's error list, which is where a reader would look for it anyway.
     *
     * @param array<string, string|null> $row
     */
    private function recordError(ImportBatch $batch, int $rowNumber, array $row, string $link): void
    {
        $error = new ImportRowError(
            $batch,
            $rowNumber,
            $row,
            sprintf('Film favori introuvable depuis le lien "%s".', $link)
        );

        $this->entityManager->persist($error);
        $batch->addRowError($error);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function optional(array $row, string $column): ?string
    {
        $value = trim((string) ($row[$column] ?? ''));

        return '' !== $value ? $value : null;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function optionalDate(array $row, string $column): ?\DateTimeImmutable
    {
        $value = $this->optional($row, $column);
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
