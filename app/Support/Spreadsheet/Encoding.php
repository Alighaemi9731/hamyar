<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * What character encoding is this CSV in, and can we say so out loud?
 *
 * A `.csv` carries no encoding declaration, so a file exported from older Iranian
 * software arrives as raw windows-1256 bytes and every naive reader produces mojibake.
 * Ours did: the header row came back **empty**, so the mapping screen offered no columns
 * to choose and the operator was left with a wizard that appeared broken.
 *
 * ## Detection is a fact, not a guess
 *
 * UTF-8 is a self-validating encoding — its multi-byte sequences follow a strict shape,
 * and Persian text in windows-1256 is a run of high bytes that essentially never forms
 * one. So "are these bytes valid UTF-8?" answers the question with near certainty in the
 * direction that matters: valid UTF-8 is UTF-8, and anything else with high bytes in it
 * is windows-1256, which is the only other encoding this market produces.
 *
 * Deliberately NOT asked of the operator. "Which code page is your export?" is a question
 * a shopkeeper cannot answer, and a wrong answer produces a silently corrupted catalogue.
 *
 * @see \App\Support\PersianText for the *other* half — the ی/ک repair the conversion
 *      then requires, and why windows-1256 makes it unavoidable rather than cosmetic.
 */
final class Encoding
{
    public const UTF8 = 'utf-8';

    public const WINDOWS_1256 = 'windows-1256';

    private const BOM = "\xEF\xBB\xBF";

    /**
     * How many bytes to read before deciding. Enough to reach real Persian text past a
     * header row, small enough that a 20MB file is not loaded to answer one question.
     */
    private const SAMPLE_BYTES = 65536;

    /**
     * The encoding of the file at `$path`.
     */
    public static function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return self::UTF8;
        }

        try {
            $sample = (string) fread($handle, self::SAMPLE_BYTES);
        } finally {
            fclose($handle);
        }

        return self::detectIn($sample);
    }

    /**
     * The encoding of a file, when only some formats have one to detect.
     *
     * A workbook is a zip or a BIFF stream, so its bytes are never valid UTF-8 and a
     * naive `detect()` would report every `.xlsx` as a legacy code page — then announce a
     * repair to the operator that never happened. The parser owns encoding inside those
     * formats; UTF-8 is the honest answer for what comes out of them.
     */
    public static function detectFor(string $path, string $extension): string
    {
        return in_array(strtolower($extension), ['csv', 'txt'], true) ? self::detect($path) : self::UTF8;
    }

    /**
     * The same decision, against bytes already in hand.
     */
    public static function detectIn(string $bytes): string
    {
        if (str_starts_with($bytes, self::BOM)) {
            return self::UTF8;
        }

        // A truncated sample can cut a multi-byte character in half and fail validation
        // for a reason that has nothing to do with the encoding, so the last few bytes
        // are dropped before the check.
        $trimmed = strlen($bytes) >= self::SAMPLE_BYTES ? substr($bytes, 0, -4) : $bytes;

        return mb_check_encoding($trimmed, 'UTF-8') ? self::UTF8 : self::WINDOWS_1256;
    }

    /**
     * Convert a line to UTF-8, from whichever encoding was detected.
     */
    public static function toUtf8(string $value, string $from): string
    {
        if ($from === self::UTF8 || $value === '') {
            return $value;
        }

        $converted = @iconv($from, 'UTF-8//TRANSLIT', $value);

        // A byte the code page has no mapping for is not worth losing the row over —
        // the rest of the line is still the shop's data.
        return $converted === false ? $value : $converted;
    }
}
