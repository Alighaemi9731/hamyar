<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A workbook from a heading row and a table of already-shaped values.
 *
 * The write half of {@see XlsxReader}. Deliberately dumb: it does no formatting, no
 * money conversion and no date rendering, because the screen the export came from has
 * already made every one of those decisions. A writer that formatted anything itself
 * would be a second opinion about what the report says, and the first person to notice
 * would be an accountant reconciling a spreadsheet against a printout.
 *
 * `ShouldAutoSize` because a Persian heading in a 64px column reads as `####`.
 */
final class ArraySheet implements FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }
}
