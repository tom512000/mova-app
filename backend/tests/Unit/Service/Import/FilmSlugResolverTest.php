<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\FilmSlugResolver;
use App\Service\Import\LetterboxdSlugExtractor;
use App\Service\Letterboxd\BoxdItResolverInterface;
use PHPUnit\Framework\TestCase;

final class FilmSlugResolverTest extends TestCase
{
    public function testResolvesBoxdItShortLinkViaRedirect(): void
    {
        $boxdItResolver = $this->createMock(BoxdItResolverInterface::class);
        $boxdItResolver->expects(self::once())
            ->method('resolve')
            ->with('tABk')
            ->willReturn('https://letterboxd.com/film/furiosa-a-mad-max-saga/');

        $resolver = new FilmSlugResolver($boxdItResolver, new LetterboxdSlugExtractor());

        self::assertSame('furiosa-a-mad-max-saga', $resolver->resolve('https://boxd.it/tABk'));
    }

    public function testReturnsNullWhenBoxdItCodeDoesNotResolve(): void
    {
        $boxdItResolver = $this->createMock(BoxdItResolverInterface::class);
        $boxdItResolver->method('resolve')->willReturn(null);

        $resolver = new FilmSlugResolver($boxdItResolver, new LetterboxdSlugExtractor());

        self::assertNull($resolver->resolve('https://boxd.it/deadcode'));
    }

    public function testAcceptsAFullLetterboxdUrlWithoutCallingBoxdIt(): void
    {
        $boxdItResolver = $this->createMock(BoxdItResolverInterface::class);
        $boxdItResolver->expects(self::never())->method('resolve');

        $resolver = new FilmSlugResolver($boxdItResolver, new LetterboxdSlugExtractor());

        self::assertSame(
            'interstellar',
            $resolver->resolve('https://letterboxd.com/johndoe/film/interstellar/')
        );
    }
}
