<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Letterboxd;

use App\DTO\Letterboxd\RssDiaryEntry;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\LetterboxdSyncStateRepository;
use App\Repository\MovieRepository;
use App\Repository\WatchRepository;
use App\Service\Letterboxd\LetterboxdRssClientInterface;
use App\Service\Letterboxd\LetterboxdRssSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The RSS feed is the only source in the whole application that knows a review hides the
 * plot — the CSV export has no column for it — so this is the only path that can ever set
 * Watch::containsSpoilers. Nothing called that setter at all until now, which is how a
 * finished reveal-on-click block on the film page came to be unreachable code.
 *
 * The feed client is stubbed: parsing the "(contains spoilers)" suffix out of an item title
 * is LetterboxdRssClient's own business and is tested there. What matters here is that the
 * flag survives the trip from the DTO to the row.
 */
final class LetterboxdRssSyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('rss@example.com', 'Rss');
        $this->user->setPassword('irrelevant-for-this-test');
        $this->user->setLetterboxdUsername('tom51200');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
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

    public function testAReviewFlaggedAsSpoilersReachesTheDatabaseMarked(): void
    {
        $this->syncWith($this->entry(containsSpoilers: true));

        self::assertTrue($this->onlyWatch()->hasSpoilers());
    }

    public function testAnOrdinaryReviewIsNotMarked(): void
    {
        // The other half of the rule: a flag nothing sets is dead, but a flag set on
        // everything is worse — it would hide every review behind a click.
        $this->syncWith($this->entry(containsSpoilers: false));

        self::assertFalse($this->onlyWatch()->hasSpoilers());
    }

    public function testTheRestOfTheEntryStillLandsAlongsideIt(): void
    {
        $this->syncWith($this->entry(containsSpoilers: true));

        $watch = $this->onlyWatch();
        self::assertSame('Une critique qui raconte la fin.', $watch->getReviewText());
        self::assertSame(4.0, $watch->getRating());
        self::assertTrue($watch->isRewatch());
        self::assertSame('2026-08-18', $watch->getWatchedDate()?->format('Y-m-d'));
    }

    public function testSyncingTwiceDoesNotDuplicateTheViewing(): void
    {
        // Idempotency rests on the item guid, exactly like diary.csv's externalRef. The
        // feed hands back the same fifty entries on every run, so this is the normal case
        // rather than an edge one.
        $entry = $this->entry(containsSpoilers: true);
        $this->syncWith($entry);
        $this->syncWith($entry);

        $this->entityManager->clear();

        self::assertCount(
            1,
            self::getContainer()->get(WatchRepository::class)->findBy(['user' => $this->user])
        );
    }

    private function entry(bool $containsSpoilers): RssDiaryEntry
    {
        return new RssDiaryEntry(
            guid: 'letterboxd-review-1456065104',
            filmTitle: 'Neuilly sa mère, sa mère !',
            filmYear: 2018,
            tmdbMovieId: 481762,
            filmSlug: 'neuilly-sa-mere-sa-mere',
            watchedDate: new \DateTimeImmutable('2026-08-18'),
            rating: 4.0,
            isRewatch: true,
            reviewText: 'Une critique qui raconte la fin.',
            containsSpoilers: $containsSpoilers,
        );
    }

    private function syncWith(RssDiaryEntry ...$entries): void
    {
        $client = $this->createMock(LetterboxdRssClientInterface::class);
        $client->method('fetchDiaryEntries')->willReturn(array_values($entries));

        $service = new LetterboxdRssSyncService(
            $client,
            self::getContainer()->get(MovieRepository::class),
            self::getContainer()->get(WatchRepository::class),
            self::getContainer()->get(LetterboxdSyncStateRepository::class),
            $this->entityManager,
            self::getContainer()->get(MessageBusInterface::class),
            new NullLogger(),
        );

        $service->sync($this->user);
    }

    private function onlyWatch(): Watch
    {
        $this->entityManager->clear();

        $watches = self::getContainer()->get(WatchRepository::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $watches);

        return $watches[0];
    }
}
