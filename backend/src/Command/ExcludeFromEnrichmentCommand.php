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
 * Permanently marks a Movie as having no TMDB movie match (e.g. its Letterboxd entry
 * is actually a TV series). Unlike app:tmdb:reset-to-ambiguous, this status is never
 * retried by EnrichMovieMessageHandler or a CSV re-import, so it won't get re-matched
 * to a wrong movie again.
 */
#[AsCommand(
    name: 'app:tmdb:exclude',
    description: 'Permanently marks a Movie as excluded from TMDB enrichment (no matching movie exists).',
)]
final class ExcludeFromEnrichmentCommand extends Command
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
            ->addArgument('reason', InputArgument::REQUIRED, 'Why no TMDB movie match exists (stored as enrichmentError)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $movie = $this->movieRepository->find((string) $input->getArgument('movieId'));
        if (null === $movie) {
            $io->error('Film introuvable.');

            return Command::FAILURE;
        }

        $movie->clearTmdbData();
        $movie->setEnrichmentStatus(EnrichmentStatus::EXCLUDED);
        $movie->setEnrichmentError((string) $input->getArgument('reason'));
        $this->entityManager->flush();

        $io->success(sprintf('Film #%s ("%s") exclu définitivement de l\'enrichissement TMDB.', $movie->getId(), $movie->getTitle()));

        return Command::SUCCESS;
    }
}
