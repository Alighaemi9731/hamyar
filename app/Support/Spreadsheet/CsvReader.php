<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;

/**
 * CSV, read a line at a time.
 *
 * Two things this handles that a naive `str_getcsv` on `file()` does not:
 *
 * - **The UTF-8 BOM.** Excel on Windows writes one, and it silently becomes part of the
 *   first header cell — so the column called "نام" does not match "نام" and the mapping
 *   screen shows a header nobody can select.
 * - **Semicolon delimiters.** A Persian Windows locale exports CSV with `;`, and the
 *   whole file then reads as a single column. The delimiter is sniffed from the header
 *   line rather than assumed.
 */
final class CsvReader implements SpreadsheetReader
{
    private const BOM = "\xEF\xBB\xBF";

    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), ['csv', 'txt'], true);
    }

    /**
     * @return iterable<int, list<string>>
     */
    public function rows(string $path, int $limit = 0): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$path}] for reading.");
        }

        try {
            $first = fgets($handle);

            if ($first === false) {
                return;
            }

            $first = str_starts_with($first, self::BOM) ? substr($first, strlen(self::BOM)) : $first;
            $delimiter = $this->sniffDelimiter($first);

            $header = str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '\\');
            yield $this->clean($header);

            $index = 1;

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                // fgetcsv yields [null] for a blank line; an empty row is not data.
                if ($row === [null]) {
                    continue;
                }

                yield $this->clean($row);

                $index++;

                if ($limit > 0 && $index > $limit) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $row
     * @return list<string>
     */
    private function clean(array $row): array
    {
        return array_values(array_map(
            static fn (?string $cell): string => trim((string) $cell),
            $row
        ));
    }

    /**
     * Whichever candidate appears most often in the header line.
     *
     * Counted outside quotes would be more correct; in practice a header row with a
     * quoted comma is rare enough that the extra parser is not worth the bugs.
     */
    private function sniffDelimiter(string $header): string
    {
        $counts = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($header, $candidate);
        }

        arsort($counts);

        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }
}
