<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Game;

use App\Entity\Movie;
use App\Service\Game\FilmTitleHangman;
use PHPUnit\Framework\TestCase;

/**
 * The masking is the only thing standing between the player and the answer here, so what
 * matters is what a slot gives away — and what the folding lets a player win with.
 */
final class FilmTitleHangmanTest extends TestCase
{
    private FilmTitleHangman $hangman;

    protected function setUp(): void
    {
        $this->hangman = new FilmTitleHangman();
    }

    public function testAnUntouchedBoardShowsTheShapeAndNothingElse(): void
    {
        $board = $this->hangman->board($this->movie('Le Roi Lion'), []);

        self::assertSame(
            [null, null, ' ', null, null, null, ' ', null, null, null, null],
            $board->chars
        );
        self::assertSame(FilmTitleHangman::LIVES, $board->livesLeft);
    }

    public function testSpacesDigitsAndPunctuationAreOnTheBoardFromTheStart(): void
    {
        $board = $this->hangman->board($this->movie('Ted 2 !'), []);

        // The word shape is what a hangman board is; hiding the punctuation too would only
        // make it unreadable.
        self::assertSame([null, null, null, ' ', '2', ' ', '!'], $board->chars);
    }

    public function testALetterRevealsEveryPlaceItOccurs(): void
    {
        $board = $this->hangman->board($this->movie('Le Roi Lion'), ['L']);

        self::assertSame(
            ['L', null, ' ', null, null, null, ' ', 'L', null, null, null],
            $board->chars
        );
    }

    public function testAnAccentIsWonWithTheBareLetterAndShownWithItsAccent(): void
    {
        // Nobody should have to find the É key to play, but the board still reads properly.
        $board = $this->hangman->board($this->movie('Amélie'), ['E']);

        self::assertSame([null, null, 'é', null, null, 'e'], $board->chars);
        self::assertSame([], $board->wrong, 'É counts as an E, so the guess was not a miss');
    }

    public function testALigatureStandsOnTheBoardBecauseNoLetterCouldWinIt(): void
    {
        // "œ" is not reachable from any single key, so a slot for it would never be filled.
        $board = $this->hangman->board($this->movie('Cœur'), []);

        self::assertSame([null, 'œ', null, null], $board->chars);
    }

    public function testAMissIsReportedAndCostsALife(): void
    {
        $board = $this->hangman->board($this->movie('Le Roi Lion'), ['L', 'Z', 'W']);

        self::assertSame(['Z', 'W'], $board->wrong);
        self::assertSame(['L', 'Z', 'W'], $board->tried);
        self::assertSame(FilmTitleHangman::LIVES - 2, $board->livesLeft);
    }

    public function testAWrongFilmCostsALifeJustLikeAWrongLetter(): void
    {
        $board = $this->hangman->board($this->movie('Le Roi Lion'), ['Z'], 2);

        self::assertSame(FilmTitleHangman::LIVES - 3, $board->livesLeft);
    }

    public function testLivesNeverGoNegative(): void
    {
        $board = $this->hangman->board($this->movie('Le Roi Lion'), ['Z', 'W'], 99);

        self::assertSame(0, $board->livesLeft);
    }

    public function testTheBoardIsSolvedOnceEveryLetterIsFound(): void
    {
        $movie = $this->movie('Ted 2 !');

        self::assertFalse($this->hangman->isSolved($movie, ['T', 'E']));
        // The digit and the punctuation were never anyone's to find.
        self::assertTrue($this->hangman->isSolved($movie, ['T', 'E', 'D']));
    }

    public function testATitleIsRevealedWholeOnceTheRunIsOver(): void
    {
        // Losing has to show the title it was hiding, not a row of blanks.
        $board = $this->hangman->board($this->movie('Amélie'), ['Z'], 0, true);

        self::assertSame(['A', 'm', 'é', 'l', 'i', 'e'], $board->chars);
        self::assertSame(['Z'], $board->wrong, 'revealing the answer must not rewrite the history');
    }

    public function testWhatCountsAsALetter(): void
    {
        self::assertSame('E', $this->hangman->normaliseLetter('e'));
        self::assertSame('E', $this->hangman->normaliseLetter('É'), 'typing the accent is allowed too');
        self::assertSame('C', $this->hangman->normaliseLetter('ç'));
        self::assertSame('A', $this->hangman->normaliseLetter(' a '));

        self::assertNull($this->hangman->normaliseLetter('4'));
        self::assertNull($this->hangman->normaliseLetter('!'));
        self::assertNull($this->hangman->normaliseLetter(''));
        self::assertNull($this->hangman->normaliseLetter('ab'), 'one letter at a time');
        self::assertNull($this->hangman->normaliseLetter('œ'), 'nothing on the board answers to it');
    }

    private function movie(string $title): Movie
    {
        return new Movie('slug-'.md5($title), $title);
    }
}
