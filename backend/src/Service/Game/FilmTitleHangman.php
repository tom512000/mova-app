<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\DTO\Game\HangmanBoardDto;
use App\Entity\Movie;

/**
 * Hangman played on a film's title.
 *
 * The title never crosses the wire while the run is open — what goes out is one slot per
 * character, holding either the character itself or null for "still hidden". That shape is
 * the game: it gives away the length and the punctuation, which is exactly what a hangman
 * board shows on paper, and nothing else.
 */
final class FilmTitleHangman
{
    /**
     * Wrong guesses allowed. Seven rather than the classic six: a French title can be short
     * and full of proper nouns ("Shrek"), where the usual opening letters all miss at once.
     */
    public const LIVES = 7;

    /**
     * The board as the player may see it, given the letters played so far.
     *
     * @param list<string> $letters    uppercased and unaccented
     * @param int          $wrongFilms wrong films named, which cost a life just like letters
     * @param bool         $reveal     once the run is over there is nothing left to hide
     */
    public function board(Movie $movie, array $letters, int $wrongFilms = 0, bool $reveal = false): HangmanBoardDto
    {
        $chars = [];

        foreach ($this->characters($movie) as $character) {
            $folded = $this->fold($character);

            // Spaces, digits and punctuation are on the board from the start, the way they
            // are when you draw the dashes by hand. Everything else waits for its letter —
            // and is then shown with its accent, though it was won with the bare one.
            $chars[] = !$this->isMaskable($folded) || $reveal || \in_array($folded, $letters, true)
                ? $character
                : null;
        }

        $wrong = $this->wrongLetters($movie, $letters);

        return new HangmanBoardDto(
            chars: $chars,
            tried: $letters,
            wrong: $wrong,
            livesLeft: max(0, self::LIVES - \count($wrong) - $wrongFilms),
            lives: self::LIVES,
        );
    }

    /**
     * @param list<string> $letters
     *
     * @return list<string> the ones the title does not contain
     */
    public function wrongLetters(Movie $movie, array $letters): array
    {
        $inTitle = $this->titleLetters($movie);

        return array_values(array_filter($letters, static fn (string $letter) => !isset($inTitle[$letter])));
    }

    /**
     * @param list<string> $letters
     */
    public function isSolved(Movie $movie, array $letters): bool
    {
        foreach (array_keys($this->titleLetters($movie)) as $letter) {
            if (!\in_array($letter, $letters, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalises what the player typed to what the board is matched on, or null when it is
     * not a letter at all. Accepts "é" as readily as "e" — either lands on E.
     */
    public function normaliseLetter(string $input): ?string
    {
        $input = trim($input);
        if (1 !== mb_strlen($input)) {
            return null;
        }

        $folded = $this->fold($input);

        return $this->isMaskable($folded) ? $folded : null;
    }

    /**
     * The distinct letters the title is made of, as a set.
     *
     * @return array<string, true>
     */
    private function titleLetters(Movie $movie): array
    {
        $letters = [];
        foreach ($this->characters($movie) as $character) {
            $folded = $this->fold($character);
            if ($this->isMaskable($folded)) {
                $letters[$folded] = true;
            }
        }

        return $letters;
    }

    /**
     * @return list<string>
     */
    private function characters(Movie $movie): array
    {
        return mb_str_split($movie->getTitle());
    }

    /**
     * Only the 26 bare letters are hidden. A ligature — the œ of "Cœur" — has no single
     * letter to be won with, so it stands on the board from the start rather than becoming
     * a slot nobody can fill.
     */
    private function isMaskable(string $folded): bool
    {
        return 1 === \strlen($folded) && $folded >= 'A' && $folded <= 'Z';
    }

    /**
     * Strips the accent and uppercases, so É, È and Ê all answer to E. Requiring the exact
     * accent would make the game about finding the key rather than about the film.
     */
    private function fold(string $character): string
    {
        $decomposed = \Normalizer::normalize($character, \Normalizer::FORM_D);
        $stripped = preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $character : $decomposed);

        return mb_strtoupper($stripped ?? $character);
    }
}
