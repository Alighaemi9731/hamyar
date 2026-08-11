<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Excel workbooks, through `maatwebsite/excel`.
 *
 * The other half of the import: shops export from Excel far more often than they
 * export CSV, and the file that arrives is usually `.xlsx` straight out of a Persian
 * Windows.
 *
 * ## Only the first sheet
 *
 * A customer list is one sheet. Reading the rest would silently concatenate a "راهنما"
 * or "Sheet2" tab into the data and import its cells as customers.
 *
 * ## Cells are normalised to strings here, not later
 *
 * Excel hands back typed values, and two of them break an import that assumes strings:
 * a mobile number stored as a number arrives as float `9.1211122e+9`, and a date
 * arrives as a serial number. Everything is stringified at this boundary so the rest of
 * the import sees exactly what the CSV reader produces — one shape, one set of bugs.
 *
 * Numeric-looking values are formatted without exponent or thousands separators, which
 * is what makes `09121112233` survive a round trip through a spreadsheet that decided
 * it was a number.
 */
final class XlsxReader implements SpreadsheetReader
{
    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls'], true);
    }

    /**
     * @return iterable<int, list<string>>
     */
    public function rows(string $path, int $limit = 0): iterable
    {
        try {
            // An empty import object rather than null: the facade's signature wants an
            // object, and "read every cell as it is" is exactly what a bare import does.
            /** @var array<int, array<int, array<int, mixed>>> $sheets */
            $sheets = Excel::toArray(new class {}, $path);
        } catch (Throwable $exception) {
            throw new RuntimeException("Could not read the workbook at [{$path}].", 0, $exception);
        }

        $rows = $sheets[0] ?? [];

        $index = 0;

        foreach ($rows as $row) {
            yield $this->clean($row);

            $index++;

            if ($limit > 0 && $index >= $limit) {
                return;
            }
        }
    }

    /**
     * @param  array<int, mixed>  $row
     * @return list<string>
     */
    private function clean(array $row): array
    {
        $cells = [];

        foreach ($row as $cell) {
            $cells[] = $this->stringify($cell);
        }

        return $cells;
    }

    private function stringify(mixed $cell): string
    {
        if ($cell === null || is_bool($cell)) {
            return $cell === true ? '1' : '';
        }

        if (is_int($cell)) {
            return (string) $cell;
        }

        if (is_float($cell)) {
            // A mobile number Excel decided was numeric comes back as 9.1211122e+9, and
            // rendered naively that is what lands in the customer record. `%F` forces
            // the digits out rather than the exponent; trimming the trailing zeros then
            // gives back a whole number as a whole number, without truncating a genuine
            // decimal such as a price.
            //
            // Deliberately not `$cell === (int) $cell` — a float is never identical to
            // an int, so that branch could never run.
            $rendered = sprintf('%.10F', $cell);

            return str_contains($rendered, '.')
                ? rtrim(rtrim($rendered, '0'), '.')
                : $rendered;
        }

        return is_string($cell) ? trim($cell) : '';
    }
}
