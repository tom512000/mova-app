<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tmdb;

use App\Service\Tmdb\TitleNormalizer;
use PHPUnit\Framework\TestCase;

final class TitleNormalizerTest extends TestCase
{
    private TitleNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TitleNormalizer();
    }

    public function testIdenticalTitlesScoreOne(): void
    {
        self::assertSame(1.0, $this->normalizer->similarity('Interstellar', 'Interstellar'));
    }

    public function testCaseAndAccentInsensitive(): void
    {
        self::assertSame(1.0, $this->normalizer->similarity('Amélie', 'amelie'));
    }

    public function testPunctuationIsIgnored(): void
    {
        self::assertSame(1.0, $this->normalizer->similarity('Spider-Man: No Way Home', 'Spider Man No Way Home'));
    }

    public function testUnrelatedTitlesScoreLow(): void
    {
        self::assertLessThan(0.5, $this->normalizer->similarity('Interstellar', 'The Notebook'));
    }

    public function testEmptyTitleScoresZero(): void
    {
        self::assertSame(0.0, $this->normalizer->similarity('', 'Interstellar'));
    }
}
