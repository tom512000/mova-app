<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mapper;

use App\Mapper\TvGenreVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The table itself. Two things are worth pinning down: that the compound television genres
 * come apart into the right film ids, and that the rest of both vocabularies is left alone —
 * a translation table that quietly rewrites more than it advertises is worse than none.
 */
final class TvGenreVocabularyTest extends TestCase
{
    public function testActionAndAdventureBecomesTwoFilmGenres(): void
    {
        self::assertSame(
            [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 12, 'name' => 'Aventure'],
            ],
            TvGenreVocabulary::translate([['id' => 10759, 'name' => 'Action & Adventure']])
        );
    }

    public function testScienceFictionAndFantasyBecomesTwoFilmGenres(): void
    {
        self::assertSame(
            [
                ['id' => 878, 'name' => 'Science-Fiction'],
                ['id' => 14, 'name' => 'Fantastique'],
            ],
            TvGenreVocabulary::translate([['id' => 10765, 'name' => 'Science-Fiction & Fantastique']])
        );
    }

    public function testAFilmPayloadPassesThroughUnchanged(): void
    {
        $genres = [
            ['id' => 28, 'name' => 'Action'],
            ['id' => 878, 'name' => 'Science-Fiction'],
            ['id' => 18, 'name' => 'Drame'],
        ];

        self::assertSame($genres, TvGenreVocabulary::translate($genres));
    }

    public function testTheSeriesOnlyGenresWithNoFilmCounterpartAreKept(): void
    {
        // Guerre & Politique is the interesting one: its halves are not interchangeable and
        // only one has a film equivalent, so folding it into Guerre would file a political
        // drama under war. Left whole on purpose, not by omission.
        $genres = [
            ['id' => 10768, 'name' => 'Guerre & Politique'],
            ['id' => 10764, 'name' => 'Réalité'],
            ['id' => 10767, 'name' => 'Talk'],
        ];

        self::assertSame($genres, TvGenreVocabulary::translate($genres));
    }

    public function testTheSharedIdsAreNotTouched(): void
    {
        // 18, 35, 80, 99 and friends carry the same name on both catalogues already. Nothing
        // to reconcile, and nothing here should try.
        $genres = [
            ['id' => 18, 'name' => 'Drame'],
            ['id' => 35, 'name' => 'Comédie'],
            ['id' => 80, 'name' => 'Crime'],
            ['id' => 99, 'name' => 'Documentaire'],
            ['id' => 16, 'name' => 'Animation'],
        ];

        self::assertSame($genres, TvGenreVocabulary::translate($genres));
    }

    public function testASeriesCarryingBothCompoundGenresKeepsTheOrderAndTheCount(): void
    {
        // The real Loki-adjacent shape: both compound ids plus a shared one.
        self::assertSame(
            [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 12, 'name' => 'Aventure'],
                ['id' => 878, 'name' => 'Science-Fiction'],
                ['id' => 14, 'name' => 'Fantastique'],
                ['id' => 18, 'name' => 'Drame'],
            ],
            TvGenreVocabulary::translate([
                ['id' => 10759, 'name' => 'Action & Adventure'],
                ['id' => 10765, 'name' => 'Science-Fiction & Fantastique'],
                ['id' => 18, 'name' => 'Drame'],
            ])
        );
    }

    public function testAGenreReachedTwiceIsReturnedOnce(): void
    {
        // Nothing in TMDB's own payloads does this today, but the split makes it possible in
        // principle, and a caller should not have to dedupe after us.
        self::assertSame(
            [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 12, 'name' => 'Aventure'],
            ],
            TvGenreVocabulary::translate([
                ['id' => 10759, 'name' => 'Action & Adventure'],
                ['id' => 28, 'name' => 'Action'],
            ])
        );
    }

    public function testAnEmptyBlockStaysEmpty(): void
    {
        self::assertSame([], TvGenreVocabulary::translate([]));
    }
}
