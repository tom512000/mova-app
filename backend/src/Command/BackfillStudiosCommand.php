<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\TmdbException;
use App\Mapper\TmdbMovieMapper;
use App\Repository\MovieRepository;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills in production companies for films that were enriched before the studio table
 * existed.
 *
 * A plain re-enrichment would do the job, but it also rewrites the title, the poster and
 * every credit — including on the rows app:tmdb:audit-matches was used to correct by hand.
 * This reads the same TMDB payload and writes nothing but the studios.
 *
 * Read-only by default; pass --apply to write.
 */
#[AsCommand(
    name: 'app:tmdb:backfill-studios',
    description: 'Récupère les studios TMDB des films déjà enrichis, sans toucher au reste de leurs données.',
)]
final class BackfillStudiosCommand extends Command
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
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les studios (sans cette option : simulation seule)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ne traite que les N premiers films')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause en millisecondes entre deux appels TMDB', '120')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retraite aussi les films qui ont déjà des studios');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $limit = null !== $input->getOption('limit') ? max(1, (int) $input->getOption('limit')) : null;
        $delayMs = max(0, (int) $input->getOption('delay'));
        $includeDone = (bool) $input->getOption('all');

        $movies = $this->movieRepository->findEnrichedForAudit($limit);
        if ([] === $movies) {
            $io->success('Aucun film enrichi à traiter.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note('Simulation : aucune écriture. Ajoutez --apply pour enregistrer.');
        }

        $io->progressStart(\count($movies));

        $filled = 0;
        $skipped = 0;
        $empty = 0;
        $failed = 0;

        foreach ($movies as $movie) {
            $io->progressAdvance();

            if (!$includeDone && !$movie->getStudios()->isEmpty()) {
                ++$skipped;
                continue;
            }

            $tmdbId = $movie->getTmdbId();
            if (null === $tmdbId) {
                ++$skipped;
                continue;
            }

            try {
                $details = $this->tmdbClient->getMovieDetails($tmdbId);
            } catch (TmdbException $exception) {
                ++$failed;
                $io->writeln(sprintf("\n  <comment>%s</comment> : %s", $movie->getTitle(), $exception->getMessage()));
                continue;
            }

            $companies = $details['production_companies'] ?? [];
            if ([] === $companies) {
                // TMDB genuinely has none for some titles; the clue simply won't be dealt.
                ++$empty;
                continue;
            }

            if ($apply) {
                $this->tmdbMovieMapper->mapStudios($movie, $companies);
                // Flushed film by film, the same boundary EnrichMovieMessageHandler uses:
                // a studio shared by two films is only found by the second one once the
                // first has actually hit the table.
                $this->entityManager->flush();
            }
            ++$filled;

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $io->progressFinish();

        $io->table(
            ['Renseignés', 'Sans studio chez TMDB', 'Ignorés', 'Échecs'],
            [[$filled, $empty, $skipped, $failed]]
        );

        $io->success($apply ? 'Studios enregistrés.' : 'Simulation terminée.');

        return Command::SUCCESS;
    }
}
