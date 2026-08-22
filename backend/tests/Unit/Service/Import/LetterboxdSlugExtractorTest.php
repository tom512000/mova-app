<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\LetterboxdSlugExtractor;
use PHPUnit\Framework\TestCase;

final class LetterboxdSlugExtractorTest extends TestCase
{
    private LetterboxdSlugExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new LetterboxdSlugExtractor();
    }

    public function testExtractsSlugFromStandardFilmUri(): void
    {
        self::assertSame(
            'dune-part-two',
            $this->extractor->extract('https://letterboxd.com/johndoe/film/dune-part-two/')
        );
    }

    public function testExtractsSlugIgnoringTrailingRewatchNumber(): void
    {
        self::assertSame(
            'dune-part-two',
            $this->extractor->extract('https://letterboxd.com/johndoe/film/dune-part-two/2/')
        );
    }

    public function testExtractsSlugFromPublicFilmUri(): void
    {
        self::assertSame(
            'interstellar',
            $this->extractor->extract('https://letterboxd.com/film/interstellar/')
        );
    }

    public function testReturnsNullWhenNoFilmSegment(): void
    {
        self::assertNull($this->extractor->extract('https://letterboxd.com/johndoe/'));
    }

    public function testReturnsNullForEmptyUri(): void
    {
        self::assertNull($this->extractor->extract(''));
    }
}
