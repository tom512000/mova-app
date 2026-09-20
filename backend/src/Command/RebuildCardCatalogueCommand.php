<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Card\CardCatalogueBuilder;
use App\Service\Card\RarityBands;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a card catalogue from the library, and prints what came out.
 *
 * Two jobs in one command, and the second is the reason it prints so much. Operationally it
 * is the first-deploy backfill and the "something drifted" repair. But the scoring weights
 * are a product decision that cannot be made from a unit test — the only honest check is
 * whether the Légendaires read like *this* library's best films — so the report below is
 * meant to be stared at while CardScoreWeights is tuned.
 *
 * The dials, in the order they matter: CardScoreWeights::PERSON_MINIMUM_WORKS sets the size
 * of the catalogue and therefore every band; WORK_PERSONAL decides how far a personal
 * favourite can climb past a blockbuster; the CEILING_* constants decide where each curve
 * stops rewarding scale.
 */
#[AsCommand(
    name: 'app:cards:rebuild',
    description: 'Reconstruit le catalogue de cartes d\'un compte et affiche la distribution des raretés.',
)]
final class RebuildCardCatalogueCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CardCatalogueBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du compte à reconstruire')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Reconstruit le catalogue de tous les comptes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $targets = $this->resolveTargets($input, $io);
        if (null === $targets) {
            return Command::INVALID;
        }

        foreach ($targets as $user) {
            $io->section($user->getEmail());
            $report = $this->builder->rebuild($user);

            $io->writeln(sprintf(
                '%d œuvres vues · %d cartes · %d hors catalogue · %.2f s',
                $report->workCount,
                $report->cardCount,
                $report->relicCount,
                $report->seconds
            ));

            if (0 === $report->cardCount) {
                $io->warning('Catalogue vide : ce compte n\'a aucune œuvre vue.');
                continue;
            }

            $io->newLine();
            $io->table(
                ['Rareté', 'Cartes', 'Part', 'Cible'],
                $this->rarityRows($report->byRarity, $report->cardCount)
            );
            $io->table(
                ['Sujet', 'Cartes'],
                array_map(
                    static fn (string $subject, int $count) => [$subject, $count],
                    array_keys($report->bySubject),
                    array_values($report->bySubject)
                )
            );

            if ([] !== $report->legendaries) {
                $io->writeln('<comment>Légendaires</comment>');
                foreach ($report->legendaries as $card) {
                    $io->writeln(sprintf('  %6s  %-10s %s', $card['score'], $card['subject'], $card['label']));
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<User>|null null when the options do not name anything to rebuild
     */
    private function resolveTargets(InputInterface $input, SymfonyStyle $io): ?array
    {
        if (true === $input->getOption('all')) {
            return $this->users->findAll();
        }

        $email = $input->getOption('user');
        if (!\is_string($email) || '' === $email) {
            $io->error('Préciser --user=<email> ou --all.');

            return null;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('Aucun compte pour « %s ».', $email));

            return null;
        }

        return [$user];
    }

    /**
     * The band distribution against what it was aimed at. The two columns should agree to
     * within a card — rank-and-cut is exact, so a gap means the bands and the SQL have
     * drifted apart, which is the one failure this report exists to catch.
     *
     * @param array<string, int> $byRarity
     *
     * @return list<array{string, int, string, string}>
     */
    private function rarityRows(array $byRarity, int $total): array
    {
        $rows = [];
        foreach (RarityBands::shares() as $rarity => $share) {
            $count = $byRarity[$rarity] ?? 0;
            $rows[] = [
                $rarity,
                $count,
                sprintf('%5.2f %%', 0 === $total ? 0.0 : 100 * $count / $total),
                sprintf('%5.2f %%', 100 * $share),
            ];
        }

        return $rows;
    }
}
