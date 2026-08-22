<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\EnrichmentStatus;
use App\Repository\MovieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Undoes a wrong TmdbResolver match: strips every TMDB-derived field (poster, runtime,
 * genres, credits, ...) so the Movie goes back to a plain CSV stub, and marks it
 * "ambiguous" so it shows up for manual review instead of silently displaying wrong data.
 * Used when TmdbResolver's automatic search+score picked an unrelated movie — most often
 * because the real Letterboxd entry is a TV series (search/movie can't match it at all).
 */
#[AsCommand(
    name: 'app:tmdb:reset-to-ambiguous',
    description: 'Strips a wrong TMDB match from a Movie and marks it ambiguous for manual review.',
)]
final class ResetToAmbiguousCommand extends Command
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('movieId', InputArgument::REQUIRED, 'Internal Movie id')
            ->addArgument('reason', InputArgument::REQUIRED, 'Why this match is wrong (stored as enrichmentError)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $movie = $this->movieRepository->find((int) $input->getArgument('movieId'));
        if (null === $movie) {
            $io->error('Film introuvable.');

            return Command::FAILURE;
        }

        $movie->setTmdbId(null);
        $movie->setImdbId(null);
        $movie->setOriginalTitle(null);
        $movie->setReleaseDate(null);
        $movie->setRuntimeMinutes(null);
        $movie->setSynopsis(null);
        $movie->setTagline(null);
        $movie->setOriginalLanguage(null);
        $movie->setBudget(null);
        $movie->setRevenue(null);
        $movie->setPopularity(null);
        $movie->setTmdbVoteAverage(null);
        $movie->setTmdbVoteCount(null);
        $movie->setPosterPath(null);
        $movie->setBackdropPath(null);
        $movie->clearGenres();
        $movie->clearCountries();
        $movie->clearCredits();
        $movie->setEnrichmentStatus(EnrichmentStatus::AMBIGUOUS);
        $movie->setEnrichmentError((string) $input->getArgument('reason'));
        $this->entityManager->flush();

        $io->success(sprintf('Film #%d ("%s") réinitialisé en ambiguous.', $movie->getId(), $movie->getTitle()));

        return Command::SUCCESS;
    }
}
