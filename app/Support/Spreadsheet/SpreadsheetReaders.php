<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;

/**
 * Picks the reader that can open a given file.
 *
 * A registry rather than a match on extension inside the importer, so adding `.xlsx`
 * support is registering one more reader and changing nothing that already works.
 *
 * Registered as a singleton in `App\Providers\AppServiceProvider`.
 */
final class SpreadsheetReaders
{
    /** @var list<SpreadsheetReader> */
    private array $readers = [];

    public function register(SpreadsheetReader $reader): void
    {
        $this->readers[] = $reader;
    }

    /**
     * @throws RuntimeException when nothing registered can open this kind of file
     */
    public function forExtension(string $extension): SpreadsheetReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->handles($extension)) {
                return $reader;
            }
        }

        throw new RuntimeException("No spreadsheet reader handles a [.{$extension}] file.");
    }

    public function supports(string $extension): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->handles($extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extensions the upload field should advertise.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_values(array_filter(
            ['csv', 'txt', 'xlsx', 'xls'],
            fn (string $extension): bool => $this->supports($extension)
        ));
    }
}
