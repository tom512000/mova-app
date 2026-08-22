<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\EnrichmentStatus;
use App\Mapper\TmdbMovieMapper;
use App\Repository\MovieRepository;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually overrides a Movie's TMDB match, for the rare case where TmdbResolver's
 * automatic search+score picked the wrong film (e.g. a title/year collision with an
 * unrelated obscure title). Bypasses TmdbResolver entirely since the correct TMDB id
 * is already known — see also TmdbResolver's confidence scoring, which can favor an
 * exact-year wrong match over a right title with an off-by-one release year.
 */
#[AsCommand(
    name: 'app:tmdb:force-match',
    description: 'Re-maps a Movie to a specific TMDB id, overriding whatever TmdbResolver previously matched.',
)]
final class ForceTmdbMatchCommand extends Command
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TmdbClientInterface $tmdbClient,
        private readonly TmdbMovieMapper $tmdbMovieMapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('movieId', InputArgument::REQUIRED, 'Internal Movie id')
            ->addArgument('tmdbId', InputArgument::REQUIRED, 'Correct TMDB movie id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $movie = $this->movieRepository->find((int) $input->getArgument('movieId'));
        if (null === $movie) {
            $io->error('Film introuvable.');

            return Command::FAILURE;
        }

        $details = $this->tmdbClient->getMovieDetails((int) $input->getArgument('tmdbId'));
        $this->tmdbMovieMapper->map($movie, $details);
        $movie->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
        $movie->setEnrichmentError(null);
        $this->entityManager->flush();

        $io->success(sprintf('Film #%d re-mappé sur "%s" (TMDB #%s).', $movie->getId(), $movie->getTitle(), $input->getArgument('tmdbId')));

        return Command::SUCCESS;
    }
}
