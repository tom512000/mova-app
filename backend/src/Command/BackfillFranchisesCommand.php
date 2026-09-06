<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\MediaType;
use App\Entity\Movie;
use App\Exception\TmdbException;
use App\Repository\MovieRepository;
use App\Service\Tmdb\FranchiseSynchroniser;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills in TMDB franchises for films enriched before the app knew what a saga was.
 *
 * Same reasoning as app:tmdb:backfill-producers: a plain re-enrichment would do the job, but
 * it also rewrites the title, the poster and every credit — including the rows
 * app:tmdb:audit-matches was used to correct by hand. This reads the same payload and writes
 * nothing but the franchise link.
 *
 * Series are skipped outright rather than fetched and discarded. TMDB has no collection
 * concept on /tv, so asking would spend a request to learn nothing.
 *
 * Works are loaded one at a time and the entity manager is emptied every so often, for the
 * reason the producers backfill learned the hard way: nine hundred works and everything
 * hanging off them do not fit in 128 MB.
 *
 * Read-only by default; pass --apply to write.
 */
#[AsCommand(
    name: 'app:tmdb:backfill-franchises',
    description: 'Rattache les films déjà enrichis à leur saga TMDB, sans toucher au reste de leurs données.',
)]
final class BackfillFranchisesCommand extends Command
{
    /** Works processed between two clears of the entity manager. */
    private const BATCH = 50;

    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly TmdbClientInterface $tmdbClient,
        private readonly FranchiseSynchroniser $franchiseSynchroniser,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les rattachements (sans cette option : simulation seule)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ne traite que les N premières œuvres')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause en millisecondes entre deux appels TMDB', '120')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retraite aussi les films déjà rattachés, et rafraîchit la composition des sagas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $limit = null !== $input->getOption('limit') ? max(1, (int) $input->getOption('limit')) : null;
        $delayMs = max(0, (int) $input->getOption('delay'));
        $refresh = (bool) $input->getOption('all');

        // Ids only. The works themselves are fetched one at a time below, so the ones
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

        $attached = 0;
        $standalone = 0;
        $skipped = 0;
        $failed = 0;
        $sagas = [];

        foreach ($ids as $index => $id) {
            $io->progressAdvance();

            // Emptied on a fixed cadence rather than after every work, and the
            // synchroniser's cache dropped with it - otherwise the next film would be
            // attached to a Franchise Doctrine has just stopped managing.
            if ($index > 0 && 0 === $index % self::BATCH) {
                $this->entityManager->clear();
                $this->franchiseSynchroniser->resetCache();
            }

            $movie = $this->movieRepository->find($id);
            if (null === $movie) {
                ++$skipped;
                continue;
            }

            // Nothing to ask for: TMDB has no collections on /tv.
            if (MediaType::SERIES === $movie->getMediaType()) {
                ++$skipped;
                continue;
            }

            if (!$refresh && null !== $movie->getFranchise()) {
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
                $franchise = $this->franchiseSynchroniser->attach($movie, $details, $refresh);
            } catch (TmdbException $exception) {
                ++$failed;
                $io->writeln(sprintf("\n  <comment>%s</comment> : %s", $movie->getTitle(), $exception->getMessage()));
                continue;
            }

            if (null === $franchise) {
                // Most films belong to no saga at all. Not a failure, just a blank.
                ++$standalone;
                continue;
            }

            ++$attached;
            $sagas[$franchise->getTmdbId()] = $franchise->getName();

            // Flushed work by work, the same boundary EnrichMovieMessageHandler uses: a
            // saga is only found by the second of its films once the first has hit the
            // table. A simulation never reaches this line, so the objects built above stay
            // in memory and die with the process - which is also why the entity manager
            // must not be cleared to "undo" them.
            if ($apply) {
                $this->entityManager->flush();
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $io->progressFinish();

        $io->table(
            ['Films rattachés', 'Sagas distinctes', 'Sans saga', 'Ignorés', 'Échecs'],
            [[$attached, \count($sagas), $standalone, $skipped, $failed]]
        );

        $io->success($apply ? 'Sagas enregistrées.' : 'Simulation terminée.');

        return Command::SUCCESS;
    }
}
