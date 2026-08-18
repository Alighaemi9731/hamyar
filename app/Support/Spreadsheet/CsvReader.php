<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use App\Support\PersianText;
use RuntimeException;

/**
 * CSV, read a line at a time.
 *
 * Three things this handles that a naive `str_getcsv` on `file()` does not:
 *
 * - **The UTF-8 BOM.** Excel on Windows writes one, and it silently becomes part of the
 *   first header cell — so the column called "نام" does not match "نام" and the mapping
 *   screen shows a header nobody can select.
 * - **Semicolon delimiters.** A Persian Windows locale exports CSV with `;`, and the
 *   whole file then reads as a single column. The delimiter is sniffed from the header
 *   line rather than assumed.
 * - **windows-1256 bytes.** Older Iranian software exports CSV in the legacy Arabic code
 *   page and a `.csv` says nothing about its encoding. Read as UTF-8 those bytes produce
 *   mojibake — in practice an *empty* header row, so the mapping screen showed no columns
 *   at all and looked broken. {@see Encoding} decides, and the ی/ک repair below is the
 *   unavoidable second half of that conversion, not a nicety: windows-1256 has no Persian
 *   yeh, so every «گوشی» in the file is really «گوشي».
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

        $encoding = Encoding::detect($path);

        try {
            $first = fgets($handle);

            if ($first === false) {
                return;
            }

            $first = str_starts_with($first, self::BOM) ? substr($first, strlen(self::BOM)) : $first;

            // Converted before the delimiter is sniffed: a comma is a comma in both
            // encodings, but the header cells either side of it are not.
            $first = Encoding::toUtf8($first, $encoding);
            $delimiter = $this->sniffDelimiter($first);

            $header = str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '\\');
            yield $this->clean($header);

            $index = 1;

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $row = array_map(
                    static fn (?string $cell): ?string => $cell === null ? null : Encoding::toUtf8($cell, $encoding),
                    $row
                );

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
        // The ی/ک repair lands here rather than in the importer so that every reader
        // produces the same text for the same shop data, whatever file it arrived in.
        return array_values(array_map(
            static fn (?string $cell): string => PersianText::normalise(trim((string) $cell)),
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
