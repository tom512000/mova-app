<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Card;

use App\Entity\Enum\CardPackKind;
use App\Entity\Enum\CardRarity;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\User;
use App\Entity\Watch;
use App\Exception\CardException;
use App\Repository\CardCabinetRepository;
use App\Service\Card\CabinetClock;
use App\Service\Card\CardCabinetService;
use App\Service\Card\CardCatalogueBuilder;
use App\Service\Card\CardMapper;
use App\Service\Card\CardPackOpener;
use App\Service\Card\CardRandomSourceInterface;
use App\Service\Card\JetonTariff;
use App\Service\Card\PackOdds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Opening a pack, against a real catalogue and a real database.
 *
 * The unit tests pin what the odds tables say; this pins what actually comes out — that the
 * draw SQL runs, that the guarantee holds, that a refusal costs nothing, and above all that
 * a free pack cannot produce a card from the two tiers it is not allowed to reach. That last
 * one is asserted against the *second* guard, the exclusion in the draw query, because the
 * first one is a table somebody could mistype.
 */
final class CardPackOpenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CardCatalogueBuilder $builder;
    private ScriptedRandomSource $random;
    private CardPackOpener $opener;
    private User $user;
    private int $counter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // The opener runs its own transaction inside this one. DBAL 4 nests with savepoints
        // by default, so its rollback on a refusal undoes only its own work and leaves this
        // test's transaction usable.
        $this->entityManager->getConnection()->beginTransaction();

        // Built by hand rather than pulled from the container: each of these has a single
        // consumer, so the compiler inlines it and even the test container cannot hand it
        // over. Constructing them here also makes the one substitution this file needs —
        // the dice — obvious rather than buried in a container override.
        $clock = new CabinetClock();
        $cabinets = new CardCabinetService($this->entityManager, self::getContainer()->get(CardCabinetRepository::class), $clock);
        $this->builder = new CardCatalogueBuilder($this->entityManager, $cabinets);
        $this->random = new ScriptedRandomSource();
        $this->opener = new CardPackOpener(
            $this->entityManager,
            $cabinets,
            $this->random,
            new CardMapper('https://image.example.test'),
            $clock,
        );

        $this->user = $this->createUser();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testTheCabinetIsShutBelowFiveHundredWatchedWorksAndTheRefusalCostsNothing(): void
    {
        $this->seedCatalogue(30);
        $this->setWorkCount(CardPackOpener::MINIMUM_WORKS - 63);
        $this->setBalance(10000);

        try {
            $this->opener->open($this->user, CardPackKind::REEL);
            self::fail('A pack should not open below the gate.');
        } catch (CardException $exception) {
            self::assertStringContainsString('500 œuvres vues', $exception->getMessage());
            self::assertStringContainsString('63', $exception->getMessage());
        }

        self::assertSame(10000, $this->balance());
        self::assertSame(0, $this->ownedCards());
    }

    public function testAPackNobodyCanAffordLeavesTheBalanceExactlyWhereItWas(): void
    {
        $this->openTheGate();
        $this->setBalance(JetonTariff::priceOf(CardPackKind::REEL) - 1);

        try {
            $this->opener->open($this->user, CardPackKind::REEL);
            self::fail('A pack should not open without the jetons for it.');
        } catch (CardException $exception) {
            self::assertStringContainsString('Il te manque 1 jetons', $exception->getMessage());
        }

        self::assertSame(JetonTariff::priceOf(CardPackKind::REEL) - 1, $this->balance());
        self::assertSame(0, $this->ownedCards());
    }

    public function testAFreePackDealsFiveCardsCostsNothingAndGuaranteesOneAboveCommune(): void
    {
        $this->openTheGate();

        $result = $this->opener->open($this->user, CardPackKind::FREE);

        self::assertCount(5, $result->cards);
        self::assertSame(0, $result->cost);
        self::assertSame(0, $this->balance());

        $last = $result->cards[4];
        self::assertTrue($last->wasGuaranteed);
        self::assertGreaterThanOrEqual(CardRarity::UNCOMMON->rank(), $last->card->rarity->rank());
    }

    /**
     * The assertion the whole free-pack design rests on.
     *
     * The dice are rigged to the best draw the table has on every single slot, so if the
     * exclusion in the draw query were missing this would pull the top of the catalogue
     * every time. Two hundred packs is a thousand cards.
     */
    public function testAFreePackNeverYieldsAnUltraRareOrALegendaireHoweverTheDiceFall(): void
    {
        $this->openTheGate();
        $this->random->alwaysTheBestDraw();

        for ($pack = 0; $pack < 200; ++$pack) {
            foreach ($this->opener->open($this->user, CardPackKind::FREE)->cards as $card) {
                self::assertLessThan(
                    CardRarity::ULTRA_RARE->rank(),
                    $card->card->rarity->rank(),
                    sprintf('a free pack produced %s', $card->card->rarity->value)
                );
            }
        }
    }

    public function testEveryPayingPackHonoursItsGuaranteedFloorOnTheLastCard(): void
    {
        $this->openTheGate();
        $this->setBalance(100000);
        // The worst draw the table allows, so the floor is the only thing holding the slot up.
        $this->random->alwaysTheWorstDraw();

        foreach ([CardPackKind::REEL, CardPackKind::BOXSET] as $kind) {
            $result = $this->opener->open($this->user, $kind);
            $last = $result->cards[array_key_last($result->cards)];

            self::assertCount($kind->cardCount(), $result->cards);
            self::assertTrue($last->wasGuaranteed);
            self::assertGreaterThanOrEqual(
                $kind->guaranteedFloor()->rank(),
                $last->card->liveRarity->rank(),
                sprintf('%s broke its %s floor', $kind->value, $kind->guaranteedFloor()->value)
            );
        }
    }

    public function testNoPackEverDealsTheSameCardTwice(): void
    {
        $this->openTheGate();
        $this->setBalance(100000);

        for ($pack = 0; $pack < 20; ++$pack) {
            $result = $this->opener->open($this->user, CardPackKind::BOXSET);
            $ids = array_map(static fn ($card) => $card->card->id, $result->cards);

            self::assertSame($ids, array_unique($ids));
        }
    }

    public function testADuplicatePaysTheTariffAndTheFirstCopyPaysNothing(): void
    {
        $this->openTheGate();
        $card = $this->soleCardOfTier(CardRarity::RARE);

        $first = $this->drawFirstCardAs(CardRarity::RARE);
        self::assertTrue($first->isNew);
        self::assertSame(1, $first->copies);
        self::assertSame(0, $first->jetons);

        $second = $this->drawFirstCardAs(CardRarity::RARE);
        self::assertFalse($second->isNew);
        self::assertSame(2, $second->copies);
        self::assertSame(JetonTariff::duplicateValue(CardRarity::RARE, 2), $second->jetons);
        self::assertSame($card, $second->card->id);
    }

    /**
     * The two-column design, from the outside.
     *
     * A card pulled as a Rare stays a Rare on the shelf when the library grows and its live
     * tier drops — but the *duplicate* is paid on what it is worth today, so a stale frozen
     * tier cannot print jetons forever.
     */
    public function testWhatWasPulledIsFrozenWhileWhatItPaysFollowsTheLiveTier(): void
    {
        $this->openTheGate();
        $cardId = $this->soleCardOfTier(CardRarity::SUPER_RARE);
        $this->drawFirstCardAs(CardRarity::SUPER_RARE);

        self::assertSame(CardRarity::SUPER_RARE->value, $this->column($cardId, 'owned_rarity'));

        // The catalogue moves under the card: it is now only a Peu Commune. Every other card
        // of that tier is pushed out so the next draw can only land on this one.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE card SET in_catalogue = FALSE WHERE user_id = :user AND rarity = 'uncommon'",
            ['user' => (string) $this->user->getId()]
        );
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE card SET rarity = 'uncommon' WHERE id = :id",
            ['id' => $cardId]
        );

        $duplicate = $this->drawFirstCardAs(CardRarity::UNCOMMON);

        self::assertSame($cardId, $duplicate->card->id);
        // Still a Super Rare on the shelf…
        self::assertSame(CardRarity::SUPER_RARE, $duplicate->card->rarity);
        self::assertSame(CardRarity::SUPER_RARE->value, $this->column($cardId, 'owned_rarity'));
        // …and paid as the Peu Commune it has become.
        self::assertSame(CardRarity::UNCOMMON, $duplicate->card->liveRarity);
        self::assertSame(JetonTariff::duplicateValue(CardRarity::UNCOMMON, 2), $duplicate->jetons);
    }

    public function testPityFiresOnItsExactCardAndResetsAfterwards(): void
    {
        $this->openTheGate();
        $this->setBalance(1000000);
        $this->random->alwaysTheWorstDraw();

        // One card short of the threshold: the dice are at the bottom of the table and
        // nothing rescues the first card.
        $this->setPity(PackOdds::PITY_SUPER_RARE - 1, 0);
        $result = $this->opener->open($this->user, CardPackKind::REEL);

        self::assertFalse($result->cards[0]->wasPity);
        self::assertSame(CardRarity::COMMON, $result->cards[0]->card->liveRarity);

        // Exactly at the threshold: the very next card is forced.
        $this->setPity(PackOdds::PITY_SUPER_RARE, 0);
        $result = $this->opener->open($this->user, CardPackKind::REEL);

        self::assertTrue($result->cards[0]->wasPity);
        self::assertGreaterThanOrEqual(
            CardRarity::SUPER_RARE->rank(),
            $result->cards[0]->card->liveRarity->rank(),
            'the card that came due should have been forced up'
        );
        // Forced, so the counter went back to zero and only the four cards after it count.
        self::assertSame(4, $this->pity('pity_super_rare'));
    }

    public function testAFreePackNeverAdvancesPity(): void
    {
        $this->openTheGate();
        $this->setPity(5, 7);

        $this->opener->open($this->user, CardPackKind::FREE);

        self::assertSame(5, $this->pity('pity_super_rare'));
        self::assertSame(7, $this->pity('pity_legendary'));
    }

    /**
     * The rule that makes unlimited free packs survivable arithmetic rather than a hope.
     *
     * The catalogue is reduced to a single Rare card, so every free pack after the first is
     * five duplicates of it — the fastest possible way to earn. The cap still holds.
     */
    public function testFreePackDuplicateJetonsStopAtTheDailyCapAndResumeTheNextDay(): void
    {
        $this->openTheGate();
        $this->keepOnly(CardRarity::RARE, 6);

        $earned = 0;
        for ($pack = 0; $pack < 60; ++$pack) {
            $earned += $this->opener->open($this->user, CardPackKind::FREE)->jetonsEarned;
        }

        self::assertSame(JetonTariff::FREE_DUPLICATE_DAILY_CAP, $earned);
        self::assertSame(JetonTariff::FREE_DUPLICATE_DAILY_CAP, $this->balance());

        // Yesterday's total is spent: a new Paris day starts the allowance over.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE card_cabinet SET free_dup_jetons_on = free_dup_jetons_on - INTERVAL '1 day' WHERE user_id = :id",
            ['id' => (string) $this->user->getId()]
        );

        $result = $this->opener->open($this->user, CardPackKind::FREE);

        self::assertGreaterThan(0, $result->jetonsEarned);
        self::assertFalse($result->dailyCapReached);
    }

    public function testAPayingPackDebitsItsPriceAndCreditsItsSalvageInOneMove(): void
    {
        $this->openTheGate();
        $this->setBalance(2000);

        $result = $this->opener->open($this->user, CardPackKind::REEL);

        self::assertSame(2000 - 500 + $result->jetonsEarned, $result->balance);
        self::assertSame($result->balance, $this->balance());
        self::assertSame(500, $result->cost);
    }

    public function testAnOpeningIsRecordedSoItCanBeReplayed(): void
    {
        $this->openTheGate();

        $result = $this->opener->open($this->user, CardPackKind::FREE);

        $stored = $this->entityManager->getConnection()->executeQuery(
            'SELECT kind, cost, jetons_earned, cards FROM card_pack_opening WHERE id = :id',
            ['id' => $result->id]
        )->fetchAssociative();

        self::assertNotFalse($stored);
        self::assertSame(CardPackKind::FREE->value, $stored['kind']);
        self::assertSame(
            array_map(static fn ($card) => $card->card->id, $result->cards),
            json_decode((string) $stored['cards'], true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testAnotherAccountsCatalogueIsNeverDrawnFrom(): void
    {
        $this->openTheGate();

        $stranger = $this->createUser();
        $this->user = $stranger;
        $this->seedCatalogue(30);
        $this->openTheGate();

        $result = $this->opener->open($stranger, CardPackKind::FREE);

        foreach ($result->cards as $card) {
            $owner = $this->entityManager->getConnection()->executeQuery(
                'SELECT user_id FROM card WHERE id = :id',
                ['id' => $card->card->id]
            )->fetchOne();

            self::assertSame((string) $stranger->getId(), (string) $owner);
        }
    }

    // ------------------------------------------------------------------ fixture

    private function openTheGate(): void
    {
        $this->seedCatalogue(30);
        $this->setWorkCount(CardPackOpener::MINIMUM_WORKS);
    }

    /**
     * Builds a small but real catalogue: works, credits and a studio, scored by the real
     * builder so every band is populated the way production populates it.
     */
    private function seedCatalogue(int $works): void
    {
        for ($i = 0; $i < $works; ++$i) {
            $movie = $this->movie($i);
            $this->entityManager->persist($movie);

            $watch = new Watch($this->user, $movie, WatchSource::CSV_IMPORT);
            $watch->setWatchedDate(new \DateTimeImmutable('2026-01-01'))
                ->setRating(0.5 + 0.5 * ($i % 10));
            $this->entityManager->persist($watch);
        }

        $this->entityManager->flush();
        $this->builder->rebuild($this->user);
    }

    private function movie(int $index): Movie
    {
        $movie = new Movie(
            sprintf('zz-carte-%d-%d', ++$this->counter, $index),
            sprintf('ZZ Carte %03d', $index)
        );
        $movie->setMediaType(MediaType::MOVIE)
            ->setReleaseYear(1970 + $index)
            ->setPopularity(1.0 + $index)
            ->setTmdbVoteAverage(5.0 + ($index % 5) / 2)
            ->setTmdbVoteCount(100 * ($index + 1))
            ->setRuntimeMinutes(90 + $index);

        return $movie;
    }

    private function createUser(): User
    {
        $user = new User(sprintf('zz-cards-%d@example.com', ++$this->counter), 'ZZ Collectionneur');
        $user->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Opens a free pack aimed so that its *first* card is of the given tier, and returns it.
     *
     * The remaining slots are aimed at Commune, which a thirty-work catalogue has plenty of,
     * because a pack never deals the same card twice: asking five slots for a tier holding
     * one card would exhaust it and the draw would correctly refuse.
     */
    private function drawFirstCardAs(CardRarity $tier): \App\DTO\Card\PackCardDto
    {
        $this->random->tiersPerSlot(CardPackKind::FREE, [
            $tier,
            CardRarity::COMMON,
            CardRarity::COMMON,
            CardRarity::COMMON,
            CardRarity::UNCOMMON,
        ]);

        return $this->opener->open($this->user, CardPackKind::FREE)->cards[0];
    }

    /** Reduces a tier to exactly one card, so a draw of that tier is deterministic. */
    private function soleCardOfTier(CardRarity $tier): string
    {
        $connection = $this->entityManager->getConnection();
        $keep = (string) $connection->executeQuery(
            'SELECT id FROM card WHERE user_id = :id AND rarity = :rarity ORDER BY catalogue_rank LIMIT 1',
            ['id' => (string) $this->user->getId(), 'rarity' => $tier->value]
        )->fetchOne();

        $connection->executeStatement(
            'UPDATE card SET in_catalogue = FALSE WHERE user_id = :id AND rarity = :rarity AND id <> :keep',
            ['id' => (string) $this->user->getId(), 'rarity' => $tier->value, 'keep' => $keep]
        );

        return $keep;
    }

    /**
     * Shrinks the catalogue to exactly $keep cards, all of one tier — the fastest possible
     * way to earn from free packs, since every pack after the first is all duplicates.
     *
     * Not one card: a pack never deals the same card twice, so a five-card pack needs at
     * least five to draw from.
     */
    private function keepOnly(CardRarity $tier, int $keep): void
    {
        $connection = $this->entityManager->getConnection();
        $userId = (string) $this->user->getId();

        $connection->executeStatement(
            'UPDATE card SET rarity = :rarity WHERE user_id = :id',
            ['id' => $userId, 'rarity' => $tier->value]
        );
        $connection->executeStatement(
            'UPDATE card SET in_catalogue = FALSE WHERE user_id = :id AND id NOT IN (
                SELECT id FROM card WHERE user_id = :id ORDER BY catalogue_rank LIMIT :keep
            )',
            ['id' => $userId, 'keep' => $keep],
            ['keep' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
    }

    private function setBalance(int $balance): void
    {
        $this->cabinetUpdate('balance = :value', $balance);
    }

    private function setWorkCount(int $works): void
    {
        $this->cabinetUpdate('catalogue_work_count = :value', $works);
    }

    private function setPity(int $superRare, int $legendary): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE card_cabinet SET pity_super_rare = :sr, pity_legendary = :l WHERE user_id = :id',
            ['id' => (string) $this->user->getId(), 'sr' => $superRare, 'l' => $legendary]
        );
    }

    private function cabinetUpdate(string $assignment, int $value): void
    {
        self::getContainer()->get(CardCabinetService::class)->ensure($this->user);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE card_cabinet SET {$assignment} WHERE user_id = :id",
            ['id' => (string) $this->user->getId(), 'value' => $value]
        );
    }

    private function balance(): int
    {
        return (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT balance FROM card_cabinet WHERE user_id = :id',
            ['id' => (string) $this->user->getId()]
        )->fetchOne();
    }

    private function pity(string $column): int
    {
        return (int) $this->entityManager->getConnection()->executeQuery(
            "SELECT {$column} FROM card_cabinet WHERE user_id = :id",
            ['id' => (string) $this->user->getId()]
        )->fetchOne();
    }

    private function ownedCards(): int
    {
        return (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM card WHERE user_id = :id AND copies > 0',
            ['id' => (string) $this->user->getId()]
        )->fetchOne();
    }

    private function column(string $cardId, string $column): ?string
    {
        $value = $this->entityManager->getConnection()->executeQuery(
            "SELECT {$column} FROM card WHERE id = :id",
            ['id' => $cardId]
        )->fetchOne();

        return null === $value || false === $value ? null : (string) $value;
    }
}

/**
 * Dice a test can aim.
 *
 * The opener takes its randomness through an interface for exactly this: proving that a free
 * pack cannot reach the top tiers means rigging every draw to ask for them, which no amount
 * of sampling a real generator can do.
 */
final class ScriptedRandomSource implements CardRandomSourceInterface
{
    private string $mode = 'best';

    private ?CardPackKind $kind = null;

    /** @var list<CardRarity> */
    private array $perSlot = [];

    private int $call = 0;

    public function alwaysTheBestDraw(): void
    {
        $this->mode = 'best';
    }

    public function alwaysTheWorstDraw(): void
    {
        $this->mode = 'worst';
    }

    /**
     * Aims each slot of the next pack at a chosen tier.
     *
     * The opener asks for one draw per slot, and the last slot is rolled against the
     * guaranteed table rather than the ordinary one, so the offset has to be computed from
     * whichever table that slot will use. Anything the opener narrows afterwards — a pity
     * floor — shifts the range, which is why the result is clamped rather than trusted.
     *
     * @param list<CardRarity> $perSlot
     */
    public function tiersPerSlot(CardPackKind $kind, array $perSlot): void
    {
        $this->mode = 'slots';
        $this->kind = $kind;
        $this->perSlot = $perSlot;
        $this->call = 0;
    }

    public function int(int $min, int $max): int
    {
        if ('slots' === $this->mode && null !== $this->kind) {
            $slot = $this->call++;
            $tier = $this->perSlot[$slot] ?? null;

            if (null === $tier) {
                return $min;
            }

            $isGuaranteed = $slot === $this->kind->cardCount() - 1;
            $odds = $isGuaranteed
                ? PackOdds::forGuaranteedSlot($this->kind)
                : PackOdds::forKind($this->kind);

            return min($max, max($min, $this->offsetOf($odds, $tier)));
        }

        return 'worst' === $this->mode ? $min : $max;
    }

    /**
     * The lowest draw that lands in a tier's band, walking the table in the ladder's order —
     * the same order PackOdds::roll() walks.
     *
     * @param array<string, int> $odds
     */
    private function offsetOf(array $odds, CardRarity $tier): int
    {
        $cumulative = 0;
        foreach (CardRarity::cases() as $rarity) {
            $weight = $odds[$rarity->value] ?? 0;
            if ($weight <= 0) {
                continue;
            }
            if ($rarity === $tier) {
                return $cumulative;
            }
            $cumulative += $weight;
        }

        return 0;
    }
}
