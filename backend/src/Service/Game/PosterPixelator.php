<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\PosterPixelsDto;
use App\Entity\Movie;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reduces a poster to the few pixels the player has earned so far.
 *
 * The downsampling happens here rather than in the browser on purpose. Sending the poster
 * and asking CSS to blur it would put the answer in the network tab — the same reason the
 * other two games never ship the film they are hiding. What leaves this class is a grid of
 * averaged colours, and at the opening rung that grid is 54 of them.
 */
final class PosterPixelator
{
    /**
     * Source widths, one per attempt: the poster gains a rung with every wrong guess.
     *
     * Roughly a factor of 1.55 apiece. Six pixels across is pure colour-blocking — palette,
     * a light source, maybe a silhouette — and 34 is a poster you recognise if you know it
     * without being one you can simply read. The count of rungs *is* the number of tries:
     * the run ends when the ladder does, exactly as the clue game ends when its facts do.
     */
    private const LADDER = [6, 9, 14, 22, 34];

    /**
     * The size fetched from TMDB. Every rung downsamples from this one image, so it wants
     * to be comfortably above the last rung and no larger — 342 is the smallest TMDB width
     * that clears 34 with room to average over.
     */
    private const SOURCE_WIDTH = 'w342';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * How many rungs the ladder has, which is also how many guesses the game allows.
     */
    public function steps(): int
    {
        return \count(self::LADDER);
    }

    /**
     * The grid for a run that has used $attemptsUsed guesses, or null when the poster
     * cannot be read — TMDB dropped it, or the file is not an image any more.
     */
    public function pixelate(Movie $movie, int $attemptsUsed): ?PosterPixelsDto
    {
        $path = $movie->getPosterPath();
        if (null === $path) {
            return null;
        }

        // A finished run has climbed past the last rung; it stops at the top rather than
        // running off the end, and the full poster is shown from the answer itself anyway.
        $index = max(0, min($attemptsUsed, $this->steps() - 1));
        $ladder = $this->ladder($path);

        if (null === $ladder) {
            return null;
        }

        return new PosterPixelsDto(
            width: $ladder[$index]['width'],
            height: $ladder[$index]['height'],
            step: $index + 1,
            steps: $this->steps(),
            colors: $ladder[$index]['colors'],
        );
    }

    /**
     * Every rung of one poster, built and cached together. A run walks all of them, so
     * fetching the artwork once and decoding it once beats paying for both five times.
     *
     * @return list<array{width: int, height: int, colors: list<string>}>|null
     */
    private function ladder(string $path): ?array
    {
        // Keyed by the poster path, so a re-enrichment that swaps the artwork invalidates
        // itself instead of serving the previous film's pixels.
        $key = 'game.poster.'.md5($path);

        return $this->cache->get($key, function (ItemInterface $item) use ($path): ?array {
            $source = $this->fetch($path);
            if (null === $source) {
                // Short TTL: a poster missing today is usually a hiccup, not a verdict.
                $item->expiresAfter(300);

                return null;
            }

            $item->expiresAfter(null); // a given path always decodes to the same pixels

            return $this->downsample($source);
        });
    }

    private function fetch(string $path): ?string
    {
        $url = $this->imageBaseUrl.'/'.self::SOURCE_WIDTH.$path;

        try {
            $response = $this->httpClient->request('GET', $url);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Could not fetch poster "{path}": {message}', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array{width: int, height: int, colors: list<string>}>|null
     */
    private function downsample(string $bytes): ?array
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('The poster game needs the GD extension to reduce artwork server-side.');
        }

        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            return null;
        }

        try {
            return array_map(fn (int $width) => $this->reduce($source, $width), self::LADDER);
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @param \GdImage $source
     *
     * @return array{width: int, height: int, colors: list<string>}
     */
    private function reduce(\GdImage $source, int $width): array
    {
        // Derived from the real image rather than assumed to be 2:3, so an odd poster is
        // squeezed into the wrong shape here instead of on screen.
        $height = max(1, (int) round($width * imagesy($source) / imagesx($source)));

        $target = imagecreatetruecolor($width, $height);
        // Resampled, not resized: each cell is the average of the block it covers, which is
        // what makes the low rungs read as fields of colour rather than as noise.
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $colors = [];
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $colors[] = sprintf('#%06x', imagecolorat($target, $x, $y) & 0xFFFFFF);
            }
        }

        imagedestroy($target);

        return ['width' => $width, 'height' => $height, 'colors' => $colors];
    }
}
