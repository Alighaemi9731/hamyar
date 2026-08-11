<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * Reading a customer list someone emailed the shop.
 *
 * An interface rather than a direct call into a parser, for two reasons that both bite
 * later: the file arrives as `.csv` or `.xlsx` depending on who exported it, and the
 * xlsx parser is a heavyweight third-party dependency whose API we do not want spread
 * through the import service.
 *
 * Implementations must be **streaming** — `rows()` returns an iterable, never an array.
 * A 5,000-row sheet read into memory as arrays of strings is survivable; the same sheet
 * with every cell object materialised is not, and the import screen is exactly where a
 * shop will hand us their biggest file.
 */
interface SpreadsheetReader
{
    /**
     * Whether this reader handles a file with the given extension.
     */
    public function handles(string $extension): bool;

    /**
     * Every row as a list of cell strings, header row included.
     *
     * @return iterable<int, list<string>>
     */
    public function rows(string $path, int $limit = 0): iterable;
}
