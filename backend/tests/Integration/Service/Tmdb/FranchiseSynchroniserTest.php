<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Tmdb;

use App\Entity\Movie;
use App\Exception\TmdbException;
use App\Repository\FranchiseRepository;
use App\Service\Tmdb\FranchiseSynchroniser;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two costs are kept apart here — naming the saga is free, listing what is in it is not —
 * and the tests that matter are the ones that pin which call happens when.
 */
final class FranchiseSynchroniserTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TmdbClientInterface&\PHPUnit\Framework\MockObject\MockObject $client;
    private FranchiseSynchroniser $synchroniser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->client = $this->createMock(TmdbClientInterface::class);
        $this->synchroniser = new FranchiseSynchroniser(
            $this->client,
            self::getContainer()->get(FranchiseRepository::class),
            $this->entityManager,
        );
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

    public function testAFilmInNoSagaIsLeftAlone(): void
    {
        // Most films belong to nothing, and asking /collection for them would be a request
        // spent to learn that.
        $this->client->expects(self::never())->method('getCollection');

        $movie = $this->film('sans-saga');

        self::assertNull($this->synchroniser->attach($movie, ['title' => 'Un film']));
        self::assertNull($movie->getFranchise());
    }

    public function testAFilmThatHasLeftItsSagaLosesTheLink(): void
    {
        // TMDB does move films in and out of collections. Leaving yesterday's answer in
        // place would keep the film in a saga it no longer belongs to.
        $this->client->method('getCollection')->willReturn($this->collection());

        $movie = $this->film('quitte');
        $this->synchroniser->attach($movie, $this->details());
        self::assertNotNull($movie->getFranchise());

        $this->synchroniser->attach($movie, ['title' => 'Un film']);

        self::assertNull($movie->getFranchise());
    }

    public function testTheSagaIsFilledInTheFirstTimeItIsSeen(): void
    {
        $this->client->expects(self::once())->method('getCollection')->willReturn($this->collection());

        $movie = $this->film('premier');
        $franchise = $this->synchroniser->attach($movie, $this->details());

        self::assertNotNull($franchise);
        self::assertSame('Saga de test', $franchise->getName());
        self::assertSame(['Le premier', 'Le second'], array_map(
            static fn ($film) => $film->getTitle(),
            $franchise->getFilms()->toArray()
        ));
    }

    public function testASecondFilmOfTheSameSagaCostsNoFurtherRequest(): void
    {
        // One call per saga, not per film: a nine-film saga costs one request however many
        // of its films the library holds.
        $this->client->expects(self::once())->method('getCollection')->willReturn($this->collection());

        $this->synchroniser->attach($this->film('premier'), $this->details());
        $this->synchroniser->attach($this->film('second'), $this->details());
    }

    public function testTheSameFilmListedTwiceByTmdbIsStoredOnce(): void
    {
        // Observed on real collections, and the unique index would reject the second row
        // mid-flush rather than at the point the duplicate came in.
        $this->client->method('getCollection')->willReturn([
            'parts' => [
                ['id' => 5001, 'title' => 'Le premier', 'release_date' => '1999-01-01'],
                ['id' => 5001, 'title' => 'Le premier', 'release_date' => '1999-01-01'],
            ],
        ]);

        $franchise = $this->synchroniser->attach($this->film('doublon'), $this->details());

        self::assertNotNull($franchise);
        self::assertCount(1, $franchise->getFilms());
    }

    public function testAnAnnouncedSequelWithNoDateIsKept(): void
    {
        // An unreleased sequel is a legitimate answer to "what is left", so an empty date
        // must not take the row out with it.
        $this->client->method('getCollection')->willReturn([
            'parts' => [['id' => 5002, 'title' => 'Le prochain', 'release_date' => '']],
        ]);

        $franchise = $this->synchroniser->attach($this->film('a-venir'), $this->details());

        self::assertNotNull($franchise);
        self::assertCount(1, $franchise->getFilms());
        self::assertNull($franchise->getFilms()->first()->getReleaseDate());
    }

    public function testTheSagaStillAttachesWhenItsContentsCannotBeFetched(): void
    {
        // The film keeps its saga name and the count stays unknown until the next run.
        // Failing the whole enrichment over a secondary call would be out of proportion.
        $this->client->method('getCollection')->willThrowException(new TmdbException('coupure'));

        $movie = $this->film('injoignable');
        $franchise = $this->synchroniser->attach($movie, $this->details());

        self::assertNotNull($franchise);
        self::assertSame($franchise, $movie->getFranchise());
        self::assertCount(0, $franchise->getFilms());
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        return [
            'title' => 'Un film',
            'belongs_to_collection' => ['id' => 4242, 'name' => 'Saga de test', 'poster_path' => '/saga.jpg'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collection(): array
    {
        return [
            'parts' => [
                ['id' => 5001, 'title' => 'Le premier', 'release_date' => '1999-01-01'],
                ['id' => 5002, 'title' => 'Le second', 'release_date' => '2003-01-01'],
            ],
        ];
    }

    private function film(string $slug): Movie
    {
        $movie = new Movie('zz-sync-'.$slug, $slug);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }
}
