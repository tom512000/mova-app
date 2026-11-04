<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Exception\ImportException;
use App\Service\Import\ListCsvReader;
use PHPUnit\Framework\TestCase;

/**
 * A list export is two CSVs stacked in one file, and these pin the seams between them: the
 * signature, the blank line, and what a list with no films at all looks like.
 */
final class ListCsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testItReadsTheListThenItsFilms(): void
    {
        // Verbatim from a real export, CRLF line endings included: fgetcsv leaves the "\r"
        // on the last field, which here is the description — empty on every row.
        $export = (new ListCsvReader())->read($this->file(
            "Letterboxd list export v7\r\n"
            ."Date,Name,Tags,URL,Description\r\n"
            ."2026-10-11,Films français (Watchlist),\"drame, comédie\",https://boxd.it/XSCwa,\r\n"
            ."\r\n"
            ."Position,Name,Year,URL,Description\r\n"
            ."1,Anatomy of a Fall,2023,https://boxd.it/yuDE,\r\n"
            ."2,La Haine,1995,https://boxd.it/2as8,Le noir et blanc\r\n"
        ));

        self::assertSame('https://boxd.it/XSCwa', $export->url);
        self::assertSame('Films français (Watchlist)', $export->name);
        self::assertNull($export->description);
        self::assertSame('drame, comédie', $export->tags);
        self::assertSame('2026-10-11', $export->date);

        self::assertCount(2, $export->rows);
        self::assertSame('Anatomy of a Fall', $export->rows[1]['Name']);
        self::assertSame('', $export->rows[1]['Description'], 'the trailing carriage return is stripped');
        self::assertSame('Le noir et blanc', $export->rows[2]['Description']);
    }

    public function testAListWithNoFilmsStopsAfterItsOwnBlock(): void
    {
        // An empty list is a real list: Letterboxd writes its header block and nothing else.
        $export = (new ListCsvReader())->read($this->file(
            "Letterboxd list export v7\nDate,Name,Tags,URL,Description\n2026-01-01,Vide,,https://boxd.it/abc,Rien encore\n"
        ));

        self::assertSame('Vide', $export->name);
        self::assertSame('Rien encore', $export->description);
        self::assertSame([], $export->rows);
    }

    public function testAQuotedDescriptionMaySpanSeveralLines(): void
    {
        // A blank line inside quotes is part of the text, not the gap between the two blocks.
        $export = (new ListCsvReader())->read($this->file(
            "Letterboxd list export v7\nDate,Name,Tags,URL,Description\n"
            ."2026-01-01,Longue,,https://boxd.it/abc,\"Premier paragraphe.\n\nSecond paragraphe.\"\n"
            ."\nPosition,Name,Year,URL,Description\n1,Heat,1995,https://boxd.it/heat,\n"
        ));

        self::assertSame("Premier paragraphe.\n\nSecond paragraphe.", $export->description);
        self::assertCount(1, $export->rows);
    }

    public function testAnotherExportIsRefused(): void
    {
        $this->expectException(ImportException::class);

        (new ListCsvReader())->read($this->file("Date,Name,Year,Letterboxd URI\n2024-01-01,Heat,1995,https://boxd.it/heat\n"));
    }

    public function testTheSignatureIsMatchedWhateverTheVersion(): void
    {
        // "v7" says the format has moved six times already; the next bump should not turn
        // every list into an unrecognised file.
        self::assertTrue(ListCsvReader::isSignature('Letterboxd list export v8'));
        self::assertTrue(ListCsvReader::isSignature("\u{FEFF}Letterboxd list export v7"));
        self::assertFalse(ListCsvReader::isSignature('Date'));
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'list');
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}
