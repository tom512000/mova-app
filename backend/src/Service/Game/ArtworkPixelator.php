<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\ArtworkPixelsDto;
use App\Entity\Movie;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reduces a film's artwork to the few pixels the player has earned so far.
 *
 * The downsampling happens here rather than in the browser on purpose. Sending the picture
 * and asking CSS to blur it would put the answer in the network tab — the same reason the
 * other games never ship the film they are hiding. What leaves this class is a grid of
 * averaged colours, and at the opening rung that grid is a few dozen of them.
 *
 * Two games are served from here. "Le film pixelisé" works on the poster; "Le décor" works
 * on the backdrop, which is a harder puzzle for reasons that are worth naming: a poster is
 * designed to be legible at a glance and usually carries the title, while a backdrop is a
 * production still — no lettering, no vertical framing around a face, often just a room.
 * It gets a wider ladder to compensate, not a longer one: both games are as long as the
 * number of rungs, and that number stays shared.
 */
final class ArtworkPixelator
{
    /**
     * Source widths for the poster, one per attempt: it gains a rung with every wrong guess.
     *
     * Roughly a factor of 1.55 apiece. Six pixels across is pure colour-blocking — palette,
     * a light source, maybe a silhouette — and 34 is a poster you recognise if you know it
     * without being one you can simply read. The count of rungs *is* the number of tries:
     * the run ends when the ladder does, exactly as the clue game ends when its facts do.
     */
    private const POSTER_LADDER = [6, 9, 14, 22, 34];

    /**
     * The backdrop's rungs. Wider at every step than the poster's, because the image itself
     * is: 16:9 against 2:3 means the same width buys barely half the vertical detail, and a
     * six-pixel backdrop is three rows of mud rather than a composition.
     */
    private const BACKDROP_LADDER = [9, 14, 22, 34, 52];

    /**
     * The sizes fetched from TMDB. Every rung downsamples from one image, so each wants to
     * be comfortably above its ladder's last rung and no larger.
     */
    private const POSTER_SOURCE = 'w342';
    private const BACKDROP_SOURCE = 'w780';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.tmdb.image_base_url%')]
        private readonly string $imageBaseUrl,
    ) {
    }

    /**
     * How many rungs a ladder has, which is also how many guesses either game allows.
     *
     * One number for both, and the assertion below is what keeps it honest: the two games
     * share a length even though they do not share a coarseness.
     */
    public function steps(): int
    {
        \assert(\count(self::POSTER_LADDER) === \count(self::BACKDROP_LADDER));

        return \count(self::POSTER_LADDER);
    }

    /**
     * The poster grid for a run that has used $attemptsUsed guesses, or null when the
     * artwork cannot be read — TMDB dropped it, or the file is not an image any more.
     */
    public function pixelate(Movie $movie, int $attemptsUsed): ?ArtworkPixelsDto
    {
        return $this->rung($movie->getPosterPath(), self::POSTER_SOURCE, self::POSTER_LADDER, $attemptsUsed);
    }

    /**
     * The same, on the backdrop.
     */
    public function pixelateBackdrop(Movie $movie, int $attemptsUsed): ?ArtworkPixelsDto
    {
        return $this->rung($movie->getBackdropPath(), self::BACKDROP_SOURCE, self::BACKDROP_LADDER, $attemptsUsed);
    }

    /**
     * @param list<int> $ladder
     */
    private function rung(?string $path, string $sourceWidth, array $ladder, int $attemptsUsed): ?ArtworkPixelsDto
    {
        if (null === $path) {
            return null;
        }

        // A finished run has climbed past the last rung; it stops at the top rather than
        // running off the end, and the full picture is shown from the answer itself anyway.
        $index = max(0, min($attemptsUsed, \count($ladder) - 1));
        $built = $this->ladder($path, $sourceWidth, $ladder);

        if (null === $built) {
            return null;
        }

        return new ArtworkPixelsDto(
            width: $built[$index]['width'],
            height: $built[$index]['height'],
            step: $index + 1,
            steps: \count($ladder),
            colors: $built[$index]['colors'],
        );
    }

    /**
     * Every rung of one image, built and cached together. A run walks all of them, so
     * fetching the artwork once and decoding it once beats paying for both five times.
     *
     * @param list<int> $ladder
     *
     * @return list<array{width: int, height: int, colors: list<string>}>|null
     */
    private function ladder(string $path, string $sourceWidth, array $ladder): ?array
    {
        // Keyed by the source width alongside the path, so a re-enrichment that swaps the
        // artwork invalidates itself instead of serving the previous film's pixels — and so
        // the two ladders cannot read each other's entries.
        $key = 'game.artwork.'.$sourceWidth.'.'.md5($path);

        return $this->cache->get($key, function (ItemInterface $item) use ($path, $sourceWidth, $ladder): ?array {
            $source = $this->fetch($path, $sourceWidth);
            if (null === $source) {
                // Short TTL: an image missing today is usually a hiccup, not a verdict.
                $item->expiresAfter(300);

                return null;
            }

            $item->expiresAfter(null); // a given path always decodes to the same pixels

            return $this->downsample($source, $ladder);
        });
    }

    private function fetch(string $path, string $sourceWidth): ?string
    {
        $url = $this->imageBaseUrl.'/'.$sourceWidth.$path;

        try {
            $response = $this->httpClient->request('GET', $url);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Could not fetch artwork "{path}": {message}', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<int> $ladder
     *
     * @return list<array{width: int, height: int, colors: list<string>}>|null
     */
    private function downsample(string $bytes, array $ladder): ?array
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('The pixel games need the GD extension to reduce artwork server-side.');
        }

        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            return null;
        }

        try {
            return array_map(fn (int $width) => $this->reduce($source, $width), $ladder);
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
        // Derived from the real image rather than assumed, so an odd aspect is squeezed into
        // the wrong shape here instead of on screen.
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
