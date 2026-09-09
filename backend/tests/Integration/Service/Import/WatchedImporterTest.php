<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\WatchSource;
use App\Entity\ImportBatch;
use App\Entity\User;
use App\Entity\Watch;
use App\Service\Import\Importers\DiaryImporter;
use App\Service\Import\Importers\RatingsImporter;
use App\Service\Import\Importers\WatchedImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * watched.csv dates the film being *marked as seen*; ratings.csv dates the *rating*. They are
 * not the same day, and treating them as one is what put a whole evening of ratings on a
 * single square of the calendar.
 *
 * On a real export the two disagree for 122 films out of 738, by a median of two weeks and by
 * as much as sixteen months: eight films the calendar showed on the 25th of July had in fact
 * been watched across six different days, one of them sixteen months earlier. This file used
 * to be skipped entirely whenever a viewing already existed, so all 738 of its rows did
 * nothing at all.
 *
 * The rule is one-directional, and that is the part worth pinning: this file may only ever
 * move a viewing *earlier*.
 */
final class WatchedImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('watched@example.com', 'Watched');
        $this->user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function testAFilmMarkedSeenBeforeItWasRatedIsMovedBackToTheDayItWasSeen(): void
    {
        // The whole point. Madame Irma was marked seen on the 24th and rated on the 25th,
        // along with seven other films rated that same evening.
        $this->importRatings('2026-07-25', '3.5');
        $this->importWatched('2026-07-24');

        $watch = $this->onlyWatch();
        self::assertSame('2026-07-24', $watch->getWatchedDate()?->format('Y-m-d'), 'the evening it was watched');
        self::assertSame('2026-07-25', $watch->getRatedOn()?->format('Y-m-d'), 'the evening it was rated');
        self::assertSame(3.5, $watch->getRating());
    }

    public function testALaterDateInThisFileIsIgnored(): void
    {
        // Only ever backwards. A date later than the one on record means this file is being
        // vaguer than whatever wrote it, not that the viewing moved.
        $this->importRatings('2026-07-20', '3.5');
        $this->importWatched('2026-07-28');

        self::assertSame('2026-07-20', $this->onlyWatch()->getWatchedDate()?->format('Y-m-d'));
    }

    public function testADiaryEntryIsLeftAlone(): void
    {
        // diary.csv states a real viewing date. Nothing here knows better than that.
        $this->importDiary('2026-07-26', '4');
        $this->importWatched('2026-07-20');

        self::assertSame('2026-07-26', $this->onlyWatch()->getWatchedDate()?->format('Y-m-d'));
    }

    public function testARevisedRatingIsNotAViewingAndIsNotMoved(): void
    {
        // A deduced row stands for a change of heart, not an evening — moving its date would
        // be moving something that never happened.
        $this->importRatings('2026-07-20', '3.5');
        $this->importRatings('2026-07-25', '2');

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(WatchSource::CSV_RERATING, $watches[1]->getSource());

        $this->importWatched('2026-07-10');

        // The first viewing moves; the revision keeps the day the note was changed.
        self::assertSame('2026-07-10', $watches[0]->getWatchedDate()?->format('Y-m-d'));
        self::assertSame('2026-07-25', $watches[1]->getWatchedDate()?->format('Y-m-d'));
    }

    public function testCorrectingTheViewingDateDoesNotInventARerating(): void
    {
        // The trap the whole rated_on column exists to avoid. Once watched.csv has moved the
        // viewing back to the 10th, the next export still says the rating was logged on the
        // 25th — and comparing that against the viewing date would read as a change of heart,
        // every single import, for every film the two files disagree about.
        $this->importRatings('2026-07-25', '3.5');
        $this->importWatched('2026-07-10');

        $this->importRatings('2026-07-25', '3.5');

        self::assertCount(1, $this->watches(), 'the same export twice is still one viewing');
        self::assertSame('2026-07-10', $this->onlyWatch()->getWatchedDate()?->format('Y-m-d'));
    }

    public function testARealSecondOpinionIsStillCaughtAfterACorrection(): void
    {
        // The other half of the same guarantee: separating the two dates must not cost the
        // detection it protects.
        $this->importRatings('2026-07-25', '3.5');
        $this->importWatched('2026-07-10');

        $this->importRatings('2026-08-30', '5');

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(WatchSource::CSV_RERATING, $watches[1]->getSource());
        self::assertSame(5.0, $watches[1]->getRating());
    }

    public function testAFilmThisFileAloneKnowsAboutStillGetsAViewing(): void
    {
        // watched.csv is the broadest of the three files: a film marked seen and never rated
        // exists nowhere else.
        $this->importWatched('2026-07-14');

        self::assertSame('2026-07-14', $this->onlyWatch()->getWatchedDate()?->format('Y-m-d'));
        self::assertNull($this->onlyWatch()->getRating());
    }

    private function importRatings(string $date, string $rating): ImportBatch
    {
        return $this->runImport(RatingsImporter::class, 'ratings.csv', ImportFileType::RATINGS, <<<CSV
            Date,Name,Year,Letterboxd URI,Rating
            {$date},Madame Irma,2006,https://letterboxd.com/tom/film/madame-irma/,{$rating}
            CSV);
    }

    private function importWatched(string $date): ImportBatch
    {
        return $this->runImport(WatchedImporter::class, 'watched.csv', ImportFileType::WATCHED, <<<CSV
            Date,Name,Year,Letterboxd URI
            {$date},Madame Irma,2006,https://letterboxd.com/tom/film/madame-irma/
            CSV);
    }

    private function importDiary(string $date, string $rating): ImportBatch
    {
        return $this->runImport(DiaryImporter::class, 'diary.csv', ImportFileType::DIARY, <<<CSV
            Date,Name,Year,Letterboxd URI,Rating,Rewatch,Tags,Watched Date
            {$date},Madame Irma,2006,https://letterboxd.com/tom/film/madame-irma/,{$rating},,,{$date}
            CSV);
    }

    /**
     * @param class-string $importer
     */
    private function runImport(string $importer, string $filename, ImportFileType $type, string $csv): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'watched').'.csv';
        file_put_contents($path, $csv);
        $this->paths[] = $path;

        $batch = new ImportBatch($this->user, $filename, $path, $type);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        self::getContainer()->get($importer)->import($path, $batch);

        return $batch;
    }

    /**
     * @return list<Watch>
     */
    private function watches(): array
    {
        return $this->entityManager->getRepository(Watch::class)
            ->createQueryBuilder('w')
            ->where('w.user = :user')
            ->setParameter('user', $this->user)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function onlyWatch(): Watch
    {
        $watches = $this->watches();
        self::assertCount(1, $watches);

        return $watches[0];
    }
}
