<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Movie;
use App\Exception\TmdbException;
use App\Mapper\TmdbMovieMapper;
use App\Mapper\TmdbSeriesMapper;
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
 * Fills in producer credits for works enriched before the role existed.
 *
 * Same reasoning as app:tmdb:backfill-studios: a plain re-enrichment would do the job, but
 * it also rewrites the title, the poster and every other credit — including on the rows
 * app:tmdb:audit-matches was used to correct by hand. This reads the same TMDB payload and
 * writes nothing but the producers.
 *
 * Films and series are asked for separately. A series id passed to /movie/{id} does not fail
 * loudly, it answers about whatever film happens to hold that number — so the media type
 * decides the endpoint, and the two payloads shape their crew differently anyway (one `job`
 * string against a `jobs` array).
 *
 * Works are loaded one at a time and the entity manager is emptied every so often. Holding
 * all of them at once is what a first run did, and it died of exhausted memory at 91 %: nine
 * hundred works, their credits and every person on them do not fit in 128 MB.
 *
 * Read-only by default; pass --apply to write.
 */
#[AsCommand(
    name: 'app:tmdb:backfill-producers',
    description: 'Récupère les producteur·rice·s TMDB des œuvres déjà enrichies, sans toucher au reste de leurs données.',
)]
final class BackfillProducersCommand extends Command
{
    /** Works processed between two clears of the entity manager. */
    private const BATCH = 50;

    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TmdbClientInterface $tmdbClient,
        private readonly TmdbMovieMapper $tmdbMovieMapper,
        private readonly TmdbSeriesMapper $tmdbSeriesMapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les crédits (sans cette option : simulation seule)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ne traite que les N premières œuvres')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause en millisecondes entre deux appels TMDB', '120')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retraite aussi les œuvres qui ont déjà des producteur·rice·s');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $limit = null !== $input->getOption('limit') ? max(1, (int) $input->getOption('limit')) : null;
        $delayMs = max(0, (int) $input->getOption('delay'));
        $includeDone = (bool) $input->getOption('all');

        // Ids only. The works themselves are fetched one at a time below, so that the ones
        // already dealt with can be released instead of piling up until the process dies.
        $ids = array_map(
            static fn (Movie $movie) => (string) $movie->getId(),
            $this->movieRepository->findEnrichedForAudit($limit)
        );
        $this->entityManager->clear();

        if ([] === $ids) {
            $io->success('Aucune œuvre enrichie à traiter.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note('Simulation : aucune écriture. Ajoutez --apply pour enregistrer.');
        }

        $io->progressStart(\count($ids));

        $filled = 0;
        $credits = 0;
        $empty = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $index => $id) {
            $io->progressAdvance();

            // Emptied on a fixed cadence rather than after every work: a clear costs a
            // little, and the mapper's person cache has to be dropped with it or the next
            // credit would point at a Person that Doctrine has just stopped managing.
            if ($index > 0 && 0 === $index % self::BATCH) {
                $this->entityManager->clear();
                $this->tmdbMovieMapper->resetPersonCache();
                $this->tmdbSeriesMapper->resetPersonCache();
            }

            $movie = $this->movieRepository->find($id);
            if (null === $movie) {
                ++$skipped;
                continue;
            }

            if (!$includeDone && $this->hasProducers($movie)) {
                ++$skipped;
                continue;
            }

            $tmdbId = $movie->getTmdbId();
            if (null === $tmdbId) {
                ++$skipped;
                continue;
            }

            $isSeries = MediaType::SERIES === $movie->getMediaType();

            try {
                $details = $isSeries
                    ? $this->tmdbClient->getTvDetails($tmdbId)
                    : $this->tmdbClient->getMovieDetails($tmdbId);
            } catch (TmdbException $exception) {
                ++$failed;
                $io->writeln(sprintf("\n  <comment>%s</comment> : %s", $movie->getTitle(), $exception->getMessage()));
                continue;
            }

            $crew = $isSeries
                ? ($details['aggregate_credits']['crew'] ?? [])
                : ($details['credits']['crew'] ?? []);

            $before = $this->producerCount($movie);
            $mapper = $isSeries ? $this->tmdbSeriesMapper : $this->tmdbMovieMapper;
            $mapper->mapProducers($movie, $crew);
            $added = $this->producerCount($movie) - $before;

            if (0 === $added) {
                // Plenty of titles genuinely credit nobody with the plain "Producer" job —
                // documentaries and older films especially. Not a failure, just a blank.
                ++$empty;
                continue;
            }

            // Flushed work by work, the same boundary EnrichMovieMessageHandler uses: a
            // person credited on two films is only found by the second once the first has
            // actually hit the table.
            //
            // A simulation simply never reaches this line. Nothing else in the command
            // flushes, so the credits built above stay in memory and die with the process —
            // which is also why the entity manager must NOT be cleared to "undo" them:
            // clearing detaches every Movie still to be processed, and the next flush then
            // finds a Credit pointing at an entity Doctrine no longer manages.
            if ($apply) {
                $this->entityManager->flush();
            }

            ++$filled;
            $credits += $added;

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $io->progressFinish();

        $io->table(
            ['Œuvres renseignées', 'Crédits ajoutés', 'Sans producteur chez TMDB', 'Ignorées', 'Échecs'],
            [[$filled, $credits, $empty, $skipped, $failed]]
        );

        $io->success($apply ? 'Producteur·rice·s enregistré·e·s.' : 'Simulation terminée.');

        return Command::SUCCESS;
    }

    private function hasProducers(Movie $movie): bool
    {
        return $this->producerCount($movie) > 0;
    }

    private function producerCount(Movie $movie): int
    {
        $count = 0;
        foreach ($movie->getCredits() as $credit) {
            if (CreditRole::PRODUCER === $credit->getRole()) {
                ++$count;
            }
        }

        return $count;
    }
}
