<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Person;

use App\Entity\Enum\CreditRole;
use App\Entity\Enum\MediaType;
use App\Entity\Enum\WatchSource;
use App\Entity\Movie;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Watch;
use App\Exception\TmdbException;
use App\Service\Person\PersonFilmographyService;
use App\Service\Tmdb\TmdbClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * "Tu en as vu 9 sur 14" is only worth printing if the 14 is a number somebody counting by
 * hand would agree with, so most of what is pinned here is what gets thrown away — TMDB
 * hands back a hundred credits for a director who made a dozen films.
 */
final class PersonFilmographyServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TmdbClientInterface&\PHPUnit\Framework\MockObject\MockObject $client;
    private PersonFilmographyService $service;
    private User $user;
    private int $tmdbId = 990000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->client = $this->createMock(TmdbClientInterface::class);
        $this->service = new PersonFilmographyService(
            $this->client,
            $this->entityManager,
            // A fresh pool per test: the real one is keyed on the TMDB id alone, so two
            // tests using the same fixture id would otherwise read each other's answer.
            new ArrayAdapter(),
            self::getContainer()->get('logger'),
            'https://image.tmdb.org/t/p',
        );

        $this->user = $this->createUser('filmographie@example.com');
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

    public function testTheTotalCountsOnlyReleasedFilmsWithARealAudience(): void
    {
        // The three filters, each on one entry. Without them a director who made two films
        // is reported as having made five.
        $this->client->method('getPersonCredits')->willReturn([
            'crew' => [
                $this->crewCredit(1, 'Un vrai film', 'Director', '2010-01-01', 5000),
                $this->crewCredit(2, 'Un autre vrai film', 'Director', '2015-01-01', 900),
                $this->crewCredit(3, 'Une série', 'Director', '2012-01-01', 5000, mediaType: 'tv'),
                $this->crewCredit(4, 'La suite annoncée', 'Director', '2099-01-01', 5000),
                $this->crewCredit(5, 'Un court-métrage obscur', 'Director', '1998-01-01', 3),
            ],
        ]);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::DIRECTOR]);

        self::assertNotNull($filmography);
        self::assertSame(2, $filmography->roles[0]->totalCount);
    }

    public function testAFilmAlreadyWatchedIsNeverDroppedForLackOfVotes(): void
    {
        // Found on the real library, not imagined. The vote floor is calibrated on the
        // audience TMDB gives Anglophone releases, so recent French films fall through it:
        // `Regarde` sits at 43 votes and `Les Chèvres !` at 60, and both had been watched.
        // Without the exemption the page showed "20 vues" beside "18 sur 27" — two columns
        // contradicting each other on the same row.
        $this->client->method('getPersonCredits')->willReturn([
            'cast' => [
                $this->castCredit(80, 'Un film français confidentiel', '2024-01-01', 43),
                $this->castCredit(81, 'Une archive jamais vue', '1980-01-01', 4),
            ],
        ]);

        $this->watched($this->film('confidentiel', tmdbId: 80));

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::ACTOR]);

        self::assertNotNull($filmography);
        self::assertSame(1, $filmography->roles[0]->watchedCount);
        self::assertSame(1, $filmography->roles[0]->totalCount, 'the unwatched archive entry stays out');
        self::assertSame([], $filmography->roles[0]->missing);
    }

    public function testAFilmCreditedUnderTwoWritingJobsCountsOnce(): void
    {
        // TMDB routinely files the same person under both Writer and Screenplay on one
        // film, and each duplicate would inflate the very total this section states.
        $this->client->method('getPersonCredits')->willReturn([
            'crew' => [
                $this->crewCredit(10, 'Le film', 'Writer', '2010-01-01', 5000),
                $this->crewCredit(10, 'Le film', 'Screenplay', '2010-01-01', 5000),
            ],
        ]);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::WRITER]);

        self::assertNotNull($filmography);
        self::assertSame(1, $filmography->roles[0]->totalCount);
    }

    public function testOnlyThePlainProducerJobCounts(): void
    {
        // Same rule CreditRole::PRODUCER states: an executive producer credit is very often
        // a financing arrangement rather than a job.
        $this->client->method('getPersonCredits')->willReturn([
            'crew' => [
                $this->crewCredit(20, 'Produit', 'Producer', '2010-01-01', 5000),
                $this->crewCredit(21, 'Financé', 'Executive Producer', '2011-01-01', 5000),
            ],
        ]);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::PRODUCER]);

        self::assertNotNull($filmography);
        self::assertSame(1, $filmography->roles[0]->totalCount);
    }

    public function testAWatchedFilmCountsEvenWhenThePersonHoldsNoCreditOnIt(): void
    {
        // The trap this section would otherwise fall into. Only the first fifteen billed
        // actors are imported, so somebody sixteenth on the call sheet holds no credit row
        // for a film that is in the library and watched. Counted as missing, the page would
        // send them off to watch a film they have already seen.
        $this->client->method('getPersonCredits')->willReturn([
            'cast' => [
                $this->castCredit(30, 'Déjà vu', '2010-01-01', 5000),
                $this->castCredit(31, 'Pas encore vu', '2011-01-01', 5000),
            ],
        ]);

        $seen = $this->film('deja-vu', tmdbId: 30);
        $this->watched($seen);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::ACTOR]);

        self::assertNotNull($filmography);
        self::assertSame(1, $filmography->roles[0]->watchedCount);
        self::assertSame(2, $filmography->roles[0]->totalCount);
        self::assertSame(['Pas encore vu'], array_map(
            static fn ($entry) => $entry->title,
            $filmography->roles[0]->missing
        ));
    }

    public function testASeriesSharingAFilmsTmdbIdIsNotMistakenForIt(): void
    {
        // TMDB numbers films and series in independent sequences, so id 40 is both a film
        // this person made and, quite legitimately, some unrelated series in the library.
        $this->client->method('getPersonCredits')->willReturn([
            'cast' => [$this->castCredit(40, 'Le film', '2010-01-01', 5000)],
        ]);

        $series = $this->film('serie-homonyme', tmdbId: 40, mediaType: MediaType::SERIES);
        $this->watched($series);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::ACTOR]);

        self::assertNotNull($filmography);
        self::assertSame(0, $filmography->roles[0]->watchedCount);
    }

    public function testOnlyTheJobsThePageAlreadyListsComeBack(): void
    {
        // Christopher Nolan is credited as a producer on eleven films. If the library only
        // ever saw him direct, his producing filmography is not what the page is about.
        $this->client->method('getPersonCredits')->willReturn([
            'crew' => [
                $this->crewCredit(50, 'Réalisé', 'Director', '2010-01-01', 5000),
                $this->crewCredit(51, 'Produit', 'Producer', '2011-01-01', 5000),
            ],
        ]);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::DIRECTOR]);

        self::assertNotNull($filmography);
        self::assertCount(1, $filmography->roles);
        self::assertSame(CreditRole::DIRECTOR, $filmography->roles[0]->role);
    }

    public function testTheMissingTitlesComeBackNewestFirst(): void
    {
        $this->client->method('getPersonCredits')->willReturn([
            'crew' => [
                $this->crewCredit(60, 'Le vieux', 'Director', '1995-01-01', 5000),
                $this->crewCredit(61, 'Le récent', 'Director', '2020-01-01', 5000),
                $this->crewCredit(62, 'Celui du milieu', 'Director', '2008-01-01', 5000),
            ],
        ]);

        $filmography = $this->service->getFilmography($this->person(), $this->user, [CreditRole::DIRECTOR]);

        self::assertNotNull($filmography);
        self::assertSame(['Le récent', 'Celui du milieu', 'Le vieux'], array_map(
            static fn ($entry) => $entry->title,
            $filmography->roles[0]->missing
        ));
    }

    public function testAPersonWithoutATmdbIdIsNotLookedUp(): void
    {
        $this->client->expects(self::never())->method('getPersonCredits');

        $person = (new Person())->setName('Sans TMDB');
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        self::assertNull($this->service->getFilmography($person, $this->user, [CreditRole::ACTOR]));
    }

    public function testTheSectionIsDroppedRatherThanFailingWhenTmdbIsDown(): void
    {
        // The rest of the page is answered from the library and is worth showing on its own.
        $this->client->method('getPersonCredits')->willThrowException(new TmdbException('coupure'));

        self::assertNull($this->service->getFilmography($this->person(), $this->user, [CreditRole::DIRECTOR]));
    }

    public function testNothingSurvivingTheFiltersMeansNoSectionAtAll(): void
    {
        // An empty "0 sur 0" would read as a bug rather than as an absence of data.
        $this->client->method('getPersonCredits')->willReturn([
            'cast' => [$this->castCredit(70, 'Archive', '2010-01-01', 2)],
        ]);

        self::assertNull($this->service->getFilmography($this->person(), $this->user, [CreditRole::ACTOR]));
    }

    /**
     * @return array<string, mixed>
     */
    private function crewCredit(int $id, string $title, string $job, string $releaseDate, int $votes, string $mediaType = 'movie'): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'job' => $job,
            'release_date' => $releaseDate,
            'vote_count' => $votes,
            'media_type' => $mediaType,
            'poster_path' => '/affiche.jpg',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function castCredit(int $id, string $title, string $releaseDate, int $votes): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'release_date' => $releaseDate,
            'vote_count' => $votes,
            'media_type' => 'movie',
            'poster_path' => null,
        ];
    }

    private function person(): Person
    {
        $person = (new Person())->setName('Filmographie')->setTmdbId(++$this->tmdbId);
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        return $person;
    }

    private function film(string $slug, int $tmdbId, MediaType $mediaType = MediaType::MOVIE): Movie
    {
        $movie = new Movie('zz-filmo-'.$slug, $slug);
        $movie->setTmdbId($tmdbId);
        $movie->setMediaType($mediaType);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie;
    }

    private function watched(Movie $movie): void
    {
        $watch = new Watch($this->user, $movie, WatchSource::CSV_IMPORT);
        $watch->setRating(4.0);
        $this->entityManager->persist($watch);
        $this->entityManager->flush();
    }

    private function createUser(string $email): User
    {
        $user = new User($email, $email);
        $user->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
