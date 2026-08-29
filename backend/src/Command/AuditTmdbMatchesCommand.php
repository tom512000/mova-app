<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\EnrichmentStatus;
use App\Entity\Movie;
use App\Exception\TmdbException;
use App\Mapper\TmdbMovieMapper;
use App\Repository\MovieRepository;
use App\Service\Letterboxd\LetterboxdFilmPageResolverInterface;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-checks every already-ENRICHED movie against the one source that can't be wrong:
 * the TMDB link Letterboxd itself publishes on the film's page.
 *
 * TmdbResolver has to guess, because a Letterboxd CSV export carries no external id —
 * it searches TMDB by title+year and accepts the top candidate when it scores high
 * enough. That guess is confidently wrong for two recurring cases:
 *
 *   1. A same-title, same-year short film outranks (or ties, within the runner-up
 *      margin) the real feature — the imported entry then displays a 5-minute short's
 *      poster, cast and runtime, which is what makes it hijack the "shortest film" stat.
 *   2. The Letterboxd entry is backed by a TMDB *series*, so /search/movie can never
 *      return the right thing and the best wrong movie wins by default.
 *
 * Case 1 is re-mapped onto the correct TMDB id, case 2 is marked EXCLUDED (terminal, so
 * no later re-import sends TmdbResolver hunting again). Anything the Letterboxd page
 * doesn't answer for is only reported, never touched.
 *
 * Runs read-only by default; pass --apply to actually write.
 */
#[AsCommand(
    name: 'app:tmdb:audit-matches',
    description: 'Vérifie les films déjà enrichis contre le lien TMDB de leur page Letterboxd et corrige les mauvais appariements.',
)]
final class AuditTmdbMatchesCommand extends Command
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly LetterboxdFilmPageResolverInterface $filmPageResolver,
        private readonly TmdbClientInterface $tmdbClient,
        private readonly TmdbMovieMapper $tmdbMovieMapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les corrections (sans cette option : simulation seule)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'N\'audite que les N premiers films')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause en millisecondes entre deux pages Letterboxd', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $limit = null !== $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $delayMicroseconds = max(0, (int) $input->getOption('delay')) * 1000;

        $movies = $this->movieRepository->findEnrichedForAudit($limit);
        if ([] === $movies) {
            $io->success('Aucun film enrichi à auditer.');

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Audit de %d film(s) enrichi(s)%s',
            \count($movies),
            $apply ? '' : ' — SIMULATION (--apply pour corriger)'
        ));

        /** @var array<string, list<array{0: string, 1: string, 2: string}>> $rows */
        $rows = ['remapped' => [], 'excluded' => [], 'conflict' => [], 'unresolved' => [], 'failed' => []];
        $okCount = 0;

        $io->progressStart(\count($movies));

        foreach ($movies as $movie) {
            $io->progressAdvance();

            $slug = $movie->getLetterboxdSlug();
            $page = $this->filmPageResolver->resolve($slug);
            $label = sprintf('#%s %s', $movie->getId(), $movie->getTitle());

            if (null !== $page['tmdbTvId']) {
                if ($apply) {
                    $movie->clearTmdbData();
                    $movie->setEnrichmentStatus(EnrichmentStatus::EXCLUDED);
                    $movie->setEnrichmentError(sprintf(
                        'Entrée Letterboxd "%s" adossée à une série TMDB (tv/%d) : aucun film correspondant.',
                        $slug,
                        $page['tmdbTvId']
                    ));
                    $this->entityManager->flush();
                }
                $rows['excluded'][] = [$label, $slug, sprintf('tv/%d', $page['tmdbTvId'])];

                $this->throttle($delayMicroseconds);

                continue;
            }

            if (null === $page['tmdbId']) {
                $rows['unresolved'][] = [$label, $slug, 'page Letterboxd sans lien TMDB'];

                $this->throttle($delayMicroseconds);

                continue;
            }

            if ($page['tmdbId'] === $movie->getTmdbId()) {
                ++$okCount;

                $this->throttle($delayMicroseconds);

                continue;
            }

            // tmdb_id is UNIQUE: if the correct id already belongs to another row, the two
            // Letterboxd slugs point at the same film and merging them is a data decision,
            // not something an audit should make silently.
            $holder = $this->movieRepository->findOneByTmdbId($page['tmdbId']);
            if (null !== $holder && !$holder->getId()->equals($movie->getId())) {
                $rows['conflict'][] = [$label, $slug, sprintf(
                    'TMDB %d déjà pris par #%d %s',
                    $page['tmdbId'],
                    $holder->getId(),
                    $holder->getTitle()
                )];

                $this->throttle($delayMicroseconds);

                continue;
            }

            $before = sprintf('%s (TMDB %d, %s)', $movie->getTitle(), (int) $movie->getTmdbId(), $this->runtimeLabel($movie));

            if (!$apply) {
                $rows['remapped'][] = [$label, $before, sprintf('→ TMDB %d', $page['tmdbId'])];

                $this->throttle($delayMicroseconds);

                continue;
            }

            try {
                $details = $this->tmdbClient->getMovieDetails($page['tmdbId']);
            } catch (TmdbException $e) {
                $rows['failed'][] = [$label, $slug, $e->getMessage()];

                $this->throttle($delayMicroseconds);

                continue;
            }

            $this->tmdbMovieMapper->map($movie, $details);
            if (null !== $page['imdbId'] && null === $movie->getImdbId()) {
                $movie->setImdbId($page['imdbId']);
            }
            $movie->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
            $movie->setEnrichmentError(null);
            $this->entityManager->flush();

            $rows['remapped'][] = [$label, $before, sprintf(
                '%s (TMDB %d, %s)',
                $movie->getTitle(),
                $page['tmdbId'],
                $this->runtimeLabel($movie)
            )];

            $this->throttle($delayMicroseconds);
        }

        $io->progressFinish();

        $this->renderSection($io, 'Ré-appariés', ['Film', 'Avant', 'Après'], $rows['remapped']);
        $this->renderSection($io, 'Exclus (séries TMDB)', ['Film', 'Slug', 'Lien Letterboxd'], $rows['excluded']);
        $this->renderSection($io, 'Conflits — à traiter à la main', ['Film', 'Slug', 'Détail'], $rows['conflict']);
        $this->renderSection($io, 'Non résolus — laissés intacts', ['Film', 'Slug', 'Raison'], $rows['unresolved']);
        $this->renderSection($io, 'Échecs TMDB', ['Film', 'Slug', 'Erreur'], $rows['failed']);

        $io->definitionList(
            ['Conformes' => (string) $okCount],
            ['Ré-appariés' => (string) \count($rows['remapped'])],
            ['Exclus' => (string) \count($rows['excluded'])],
            ['Conflits' => (string) \count($rows['conflict'])],
            ['Non résolus' => (string) \count($rows['unresolved'])],
            ['Échecs' => (string) \count($rows['failed'])],
        );

        if (!$apply) {
            $io->warning('Simulation : aucune modification écrite. Relancer avec --apply pour corriger.');

            return Command::SUCCESS;
        }

        $io->success('Audit terminé.');

        return Command::SUCCESS;
    }

    /**
     * LetterboxdFilmPageResolver caches every slug permanently, so a re-run costs nothing;
     * the pause only matters on the first pass, which does hit letterboxd.com once per film.
     */
    private function throttle(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    private function runtimeLabel(Movie $movie): string
    {
        $runtime = $movie->getRuntimeMinutes();

        return null === $runtime ? 'durée inconnue' : $runtime.' min';
    }

    /**
     * @param list<string>                                 $headers
     * @param list<array{0: string, 1: string, 2: string}> $rows
     */
    private function renderSection(SymfonyStyle $io, string $title, array $headers, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $io->section(sprintf('%s (%d)', $title, \count($rows)));
        $io->table($headers, $rows);
    }
}
