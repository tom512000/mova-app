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
 * Fills in the French theatrical release date for films enriched before the column existed.
 *
 * Same reasoning as app:tmdb:backfill-studios: a plain re-enrichment would fetch the very
 * same payload but also rewrite the title, the poster and every credit — including on rows
 * app:tmdb:audit-matches was used to correct by hand. This writes one field and nothing else.
 *
 * A film with no French theatrical date is not a failure and not worth retrying: it went
 * straight to streaming here, and the statistic falls back to TMDB's primary release date
 * for it. The two are counted separately below so the tally says which is which.
 *
 * Read-only by default; pass --apply to write.
 */
#[AsCommand(
    name: 'app:tmdb:backfill-french-releases',
    description: 'Récupère la date de sortie salle française des films déjà enrichis, sans toucher au reste de leurs données.',
)]
final class BackfillFrenchReleasesCommand extends Command
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
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les dates (sans cette option : simulation seule)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ne traite que les N premiers films')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause en millisecondes entre deux appels TMDB', '120')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retraite aussi les films qui ont déjà une date française');
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
        $noFrenchRelease = 0;
        $skipped = 0;
        $failed = 0;
        $moved = [];

        foreach ($movies as $movie) {
            $io->progressAdvance();

            // Series have no /movie payload and no release_dates block, and the statistic
            // this feeds is films-only anyway.
            if ($movie->isSeries() || null === $movie->getTmdbId()) {
                ++$skipped;
                continue;
            }

            if (!$includeDone && null !== $movie->getFrenchReleaseDate()) {
                ++$skipped;
                continue;
            }

            try {
                $details = $this->tmdbClient->getMovieDetails($movie->getTmdbId());
            } catch (TmdbException $exception) {
                ++$failed;
                $io->writeln(sprintf("\n  <comment>%s</comment> : %s", $movie->getTitle(), $exception->getMessage()));
                continue;
            }

            $french = $this->tmdbMovieMapper->frenchTheatricalRelease($details['release_dates']['results'] ?? []);

            if (null === $french) {
                // Straight to streaming here. Left null on purpose: the statistic reads it
                // as "fall back to the primary date", which is the right answer.
                ++$noFrenchRelease;
            } else {
                $primary = $movie->getReleaseDate();
                if (null !== $primary && $primary->format('Y-m-d') !== $french->format('Y-m-d')) {
                    $moved[] = [
                        $movie->getTitle(),
                        $primary->format('Y-m-d'),
                        $french->format('Y-m-d'),
                        sprintf('%+d j', (int) $primary->diff($french)->format('%r%a')),
                    ];
                }
                ++$filled;
            }

            if ($apply) {
                $movie->setFrenchReleaseDate($french);
                $this->entityManager->flush();
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $io->progressFinish();

        $io->table(
            ['Date FR trouvée', 'Sans sortie salle FR', 'Ignorés', 'Échecs'],
            [[$filled, $noFrenchRelease, $skipped, $failed]]
        );

        // The films where it actually changes something. Everything else got a French date
        // identical to the primary one, which is the common case and not worth a line each.
        if ([] !== $moved) {
            $io->section(sprintf('%d film(s) dont la date française diffère', \count($moved)));
            $io->table(['Film', 'Primaire', 'France', 'Écart'], $moved);
        }

        $io->success($apply ? 'Dates enregistrées.' : 'Simulation terminée.');

        return Command::SUCCESS;
    }
}
