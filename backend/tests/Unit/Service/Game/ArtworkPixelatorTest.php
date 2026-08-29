<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Game;

use App\Entity\Movie;
use App\Service\Game\ArtworkPixelator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The two pixel games live or die on this class: it is the only thing standing between the
 * player and the answer, since anything it emits is on screen and in the payload. So what
 * matters here is that a rung really is as coarse as it claims to be.
 */
final class ArtworkPixelatorTest extends TestCase
{
    private const BASE_URL = 'https://image.tmdb.org/t/p';

    /** The rungs, in order, as the player climbs them. */
    private const LADDER = [6, 9, 14, 22, 34];

    public function testTheOpeningRungIsAHandfulOfPixels(): void
    {
        $pixels = $this->pixelator($this->poster())->pixelate($this->movie(), 0);

        self::assertNotNull($pixels);
        self::assertSame(6, $pixels->width);
        // The 2:3 shape of the source is kept rather than assumed.
        self::assertSame(9, $pixels->height);
        self::assertCount(54, $pixels->colors);
        self::assertSame(1, $pixels->step);
        self::assertSame(5, $pixels->steps);
    }

    public function testEachGuessSpentSharpensItByOneRung(): void
    {
        $pixelator = $this->pixelator($this->poster());
        $movie = $this->movie();

        foreach (self::LADDER as $attemptsUsed => $expected) {
            $pixels = $pixelator->pixelate($movie, $attemptsUsed);

            self::assertNotNull($pixels);
            self::assertSame($expected, $pixels->width, "after {$attemptsUsed} guesses");
            self::assertCount($pixels->width * $pixels->height, $pixels->colors);
        }
    }

    public function testTheLadderStopsAtTheTopInsteadOfRunningOffTheEnd(): void
    {
        // A finished run has spent every attempt, so the index is already past the last rung.
        $pixels = $this->pixelator($this->poster())->pixelate($this->movie(), 12);

        self::assertNotNull($pixels);
        self::assertSame(34, $pixels->width);
        self::assertSame(5, $pixels->step);
    }

    public function testTheRungCountIsTheNumberOfTries(): void
    {
        // The game's length is not a separate constant to keep in step — running out of
        // sharpness is what ends the run.
        self::assertSame(\count(self::LADDER), $this->pixelator($this->poster())->steps());
    }

    public function testPixelsAreLaidOutLeftToRightThenTopToBottom(): void
    {
        // Solid red on the left half, solid blue on the right.
        $pixels = $this->pixelator($this->halves())->pixelate($this->movie(), 0);

        self::assertNotNull($pixels);
        self::assertSame(
            ['#ff0000', '#ff0000', '#ff0000', '#0000ff', '#0000ff', '#0000ff'],
            \array_slice($pixels->colors, 0, 6),
            'the first six colours are the top row, read left to right'
        );
    }

    public function testACellIsTheAverageOfItsBlockRatherThanOnePixelOfIt(): void
    {
        // One-pixel red and blue stripes: sampling would return a pure red or a pure blue
        // per cell, averaging returns the purple in between. That difference is the whole
        // reason the low rungs read as fields of colour instead of as noise.
        $pixels = $this->pixelator($this->stripes())->pixelate($this->movie(), 0);

        self::assertNotNull($pixels);
        foreach ($pixels->colors as $index => $color) {
            $rgb = (int) hexdec(substr($color, 1));
            $red = ($rgb >> 16) & 0xFF;
            $blue = $rgb & 0xFF;

            self::assertSame(0, ($rgb >> 8) & 0xFF, "cell {$index} invented a green channel");
            self::assertGreaterThan(0x60, $red, "cell {$index} sampled instead of averaging");
            self::assertGreaterThan(0x60, $blue, "cell {$index} sampled instead of averaging");
        }
    }

    public function testTheArtworkIsFetchedOnceForTheWholeLadder(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame(self::BASE_URL.'/w342/poster.jpg', $url);

            return new MockResponse($this->poster());
        });

        $pixelator = new ArtworkPixelator($client, new ArrayAdapter(), new NullLogger(), self::BASE_URL);
        $movie = $this->movie();

        foreach (array_keys(self::LADDER) as $attemptsUsed) {
            $pixelator->pixelate($movie, $attemptsUsed);
        }

        // Every rung comes out of one download and one decode: a run walks all five.
        self::assertSame(1, $calls);
    }

    public function testAFilmWithoutArtworkHasNothingToShow(): void
    {
        $movie = new Movie('nothing-to-see', 'Sans affiche');

        self::assertNull($this->pixelator($this->poster())->pixelate($movie, 0));
    }

    public function testAPosterTmdbNoLongerServesIsNotFatal(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        $pixelator = new ArtworkPixelator($client, new ArrayAdapter(), new NullLogger(), self::BASE_URL);

        // The board says so rather than showing an empty frame the player reads as a clue.
        self::assertNull($pixelator->pixelate($this->movie(), 0));
    }

    public function testSomethingThatIsNotAnImageIsNotFatalEither(): void
    {
        $client = new MockHttpClient(new MockResponse('<!doctype html><title>404</title>'));
        $pixelator = new ArtworkPixelator($client, new ArrayAdapter(), new NullLogger(), self::BASE_URL);

        self::assertNull($pixelator->pixelate($this->movie(), 0));
    }

    public function testTheBackdropStartsWiderThanThePosterBecauseTheImageIs(): void
    {
        // 16:9 against 2:3: six pixels across a backdrop is three rows of mud, so "Le décor"
        // opens on a coarser-looking but genuinely wider grid.
        $pixels = $this->pixelator($this->backdrop())->pixelateBackdrop($this->wideMovie(), 0);

        self::assertNotNull($pixels);
        self::assertSame(9, $pixels->width);
        self::assertSame(5, $pixels->height, 'the 16:9 shape of the source is kept');
        self::assertCount(45, $pixels->colors);
    }

    public function testBothLaddersAreTheSameLength(): void
    {
        // The two games share a number of tries even though they do not share a coarseness,
        // and steps() is the single place either of them reads it from.
        $pixelator = $this->pixelator($this->backdrop());

        $last = $pixelator->pixelateBackdrop($this->wideMovie(), $pixelator->steps() - 1);

        self::assertNotNull($last);
        self::assertSame($pixelator->steps(), $last->steps);
        self::assertSame(52, $last->width, 'the top rung of the backdrop ladder');
    }

    public function testTheBackdropIsFetchedAtItsOwnSourceWidth(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse($this->backdrop());
        });

        $pixelator = new ArtworkPixelator($client, new ArrayAdapter(), new NullLogger(), self::BASE_URL);
        $pixelator->pixelateBackdrop($this->wideMovie(), 0);

        // w780 rather than the poster's w342: the top rung is 52 across and needs room to
        // average over.
        self::assertSame([self::BASE_URL.'/w780/backdrop.jpg'], $urls);
    }

    public function testAFilmWithoutABackdropHasNothingToShowEither(): void
    {
        // Carrying a poster is not carrying a backdrop — the two games ask different things
        // of the same film, and the draw is what keeps them apart.
        self::assertNull($this->pixelator($this->poster())->pixelateBackdrop($this->movie(), 0));
    }

    private function pixelator(string $bytes): ArtworkPixelator
    {
        return new ArtworkPixelator(
            new MockHttpClient(fn () => new MockResponse($bytes)),
            new ArrayAdapter(),
            new NullLogger(),
            self::BASE_URL,
        );
    }

    private function movie(): Movie
    {
        return (new Movie('un-film', 'Un film'))->setPosterPath('/poster.jpg');
    }

    private function wideMovie(): Movie
    {
        return (new Movie('un-film-large', 'Un film'))->setBackdropPath('/backdrop.jpg');
    }

    /** A plain 16:9 backdrop, in the proportions TMDB actually serves. */
    private function backdrop(): string
    {
        return $this->png(96, 54, static function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 95, 53, imagecolorallocate($image, 0x99, 0x66, 0x33));
        });
    }

    /** A plain 2:3 poster, in the proportions TMDB actually serves. */
    private function poster(): string
    {
        return $this->png(60, 90, static function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 59, 89, imagecolorallocate($image, 0x33, 0x66, 0x99));
        });
    }

    private function halves(): string
    {
        return $this->png(60, 90, static function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 29, 89, imagecolorallocate($image, 0xFF, 0x00, 0x00));
            imagefilledrectangle($image, 30, 0, 59, 89, imagecolorallocate($image, 0x00, 0x00, 0xFF));
        });
    }

    private function stripes(): string
    {
        return $this->png(60, 90, static function (\GdImage $image): void {
            $red = imagecolorallocate($image, 0xFF, 0x00, 0x00);
            $blue = imagecolorallocate($image, 0x00, 0x00, 0xFF);
            for ($x = 0; $x < 60; ++$x) {
                imagefilledrectangle($image, $x, 0, $x, 89, 0 === $x % 2 ? $red : $blue);
            }
        });
    }

    private function png(int $width, int $height, callable $paint): string
    {
        $image = imagecreatetruecolor($width, $height);
        $paint($image);

        ob_start();
        // PNG rather than JPEG: the assertions above are about exact channel values, and
        // JPEG would smear them before the pixelator ever saw them.
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
