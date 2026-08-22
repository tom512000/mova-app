<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\EnrichMovieMessage;
use App\Repository\MovieRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:tmdb:retry-failed',
    description: 'Re-queues TMDB enrichment for movies stuck in "failed" or "ambiguous" status.',
)]
final class RetryFailedEnrichmentsCommand extends Command
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of movies to re-queue', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $movies = $this->movieRepository->findNeedingEnrichment($limit);
        foreach ($movies as $movie) {
            $this->messageBus->dispatch(new EnrichMovieMessage($movie->getId()));
        }

        $io->success(sprintf('%d film(s) re-mis en file pour enrichissement TMDB.', \count($movies)));

        return Command::SUCCESS;
    }
}
