<?php

declare(strict_types=1);

use App\Support\PersianText;
use App\Support\Spreadsheet\Encoding;
use App\Support\Spreadsheet\SpreadsheetReaders;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The same catalogue, in four file formats, must read back identically.
 *
 * A shop's export arrives as whichever format their old software emits, and the importer
 * downstream cannot be written four times. So the reader layer's job is to make the
 * format invisible — and the only way to know it does is to build one catalogue, write it
 * four ways, and compare the parsed rows against each other rather than against a
 * hand-typed expectation.
 *
 * ## The fixture is constrained by what windows-1256 can hold, and that is the point
 *
 * The code page has no Persian yeh (ی), no Persian digits (۱۲۳) and no Arabic-Indic ones
 * either. A "cp1256 fixture" containing «گوشی» is therefore **impossible** — `iconv`
 * refuses it — and one containing Persian digits is equally impossible. So the shared
 * catalogue below uses Latin digits and lets the cp1256 variant carry Arabic yeh, which
 * is exactly what a real legacy export contains.
 *
 * That constraint is the finding: ی/ک normalisation is **code-page repair**, not tidying.
 * Without it the cp1256 file reads back as «گوشي» while the other three read «گوشی», the
 * four paths disagree, and this test fails. It is the guard on that rule.
 */

/**
 * One catalogue, written four ways.
 *
 * @return list<list<string>>
 */
function sharedCatalogue(): array
{
    return [
        ['نام کالا', 'بارکد', 'قیمت'],
        ['گوشی موبایل سامسونگ گلکسی A55', '6260000000019', '189000000'],
        ['شارژر 20 وات اورجینال', '6260000000026', '4500000'],
        ['قاب محافظ شفاف', '6260000000033', '1800000'],
    ];
}

/**
 * @param  list<list<string>>  $rows
 */
function writeFixtures(array $rows, string $directory): void
{
    $csv = implode("\n", array_map(static fn (array $row): string => implode(',', $row), $rows))."\n";

    file_put_contents($directory.'/catalogue.csv', $csv);

    // What the code page forces: Persian yeh has no encoding, so a real export carries
    // the Arabic one. Written this way deliberately rather than by accident.
    $legacy = iconv('UTF-8', 'windows-1256', str_replace(PersianText::PERSIAN_YEH, PersianText::ARABIC_YEH, $csv));

    file_put_contents($directory.'/catalogue-1256.csv', (string) $legacy);

    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($directory.'/catalogue.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xls')->save($directory.'/catalogue.xls');
}

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/product-import-'.bin2hex(random_bytes(6));
    mkdir($this->directory);

    writeFixtures(sharedCatalogue(), $this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory.'/*') ?: []);
    rmdir($this->directory);
});

it('reads the same rows out of every format a shop might send', function (): void {
    $readers = app(SpreadsheetReaders::class);

    $parsed = [];

    foreach ([
        'csv (utf-8)' => ['catalogue.csv', 'csv'],
        'csv (windows-1256)' => ['catalogue-1256.csv', 'csv'],
        'xlsx' => ['catalogue.xlsx', 'xlsx'],
        'xls (legacy BIFF)' => ['catalogue.xls', 'xls'],
    ] as $label => [$file, $extension]) {
        $rows = [];

        foreach ($readers->forExtension($extension)->rows($this->directory.'/'.$file) as $row) {
            $rows[] = $row;
        }

        $parsed[$label] = $rows;
    }

    // Compared against the source catalogue AND against each other: matching the source
    // proves correctness, matching each other proves the format is invisible.
    foreach ($parsed as $label => $rows) {
        expect($rows)->toBe(sharedCatalogue(), "format [{$label}] did not read back the source catalogue");
    }
});

it('detects the legacy code page rather than asking the shopkeeper', function (): void {
    expect(Encoding::detect($this->directory.'/catalogue-1256.csv'))->toBe(Encoding::WINDOWS_1256);
    expect(Encoding::detect($this->directory.'/catalogue.csv'))->toBe(Encoding::UTF8);
});

it('does not report a workbook as a legacy code page', function (): void {
    // A zip or a BIFF stream is never valid UTF-8, so a naive check reports every .xlsx
    // as windows-1256 — and then announces a repair to the operator that never happened.
    expect(Encoding::detectFor($this->directory.'/catalogue.xlsx', 'xlsx'))->toBe(Encoding::UTF8);
    expect(Encoding::detectFor($this->directory.'/catalogue.xls', 'xls'))->toBe(Encoding::UTF8);
    expect(Encoding::detectFor($this->directory.'/catalogue-1256.csv', 'csv'))->toBe(Encoding::WINDOWS_1256);
});

it('recognises the windows-1256 fingerprint only where it belongs', function (): void {
    // Arabic yeh beside Persian kaf is a mixture no keyboard produces — only a trip
    // through a code page that carries one and drops the other.
    $legacy = Encoding::toUtf8(
        (string) file_get_contents($this->directory.'/catalogue-1256.csv'),
        Encoding::WINDOWS_1256
    );

    expect(PersianText::looksLikeLegacyCodePage($legacy))->toBeTrue();
    expect(PersianText::looksLikeLegacyCodePage((string) file_get_contents($this->directory.'/catalogue.csv')))->toBeFalse();
});

it('cannot even build a cp1256 fixture containing Persian yeh', function (): void {
    // The finding, pinned as an assertion so it cannot quietly stop being true: the
    // reason ی/ک normalisation is repair rather than tidying is that the code page
    // physically has no room for the character.
    expect(@iconv('UTF-8', 'windows-1256', 'گوشی'))->toBeFalse();
    expect(@iconv('UTF-8', 'windows-1256', '۱۲۳'))->toBeFalse();

    // …while Persian kaf goes through untouched, which is what makes the mixture
    // diagnostic rather than merely broken.
    expect(@iconv('UTF-8', 'windows-1256', PersianText::PERSIAN_KAF))->not->toBeFalse();
});
