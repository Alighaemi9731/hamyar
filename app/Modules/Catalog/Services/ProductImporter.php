<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Digits;
use App\Support\Money;
use App\Support\PersianText;
use App\Support\Spreadsheet\Encoding;
use App\Support\Spreadsheet\SpreadsheetReaders;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Loading a shop's existing catalogue out of the spreadsheet they already have.
 *
 * Onboarding-blocking rather than a convenience: an evaluator arrives with a catalogue
 * exported from older Iranian software, and one that cannot be loaded is an evaluator
 * lost on day one.
 *
 * Structurally the party import ({@see \App\Modules\CRM\Services\PartyImporter}) and for
 * the same reasons — `analyse()` and `import()` walk **identical** code and only the
 * second commits, because an import that reports one outcome and performs another is
 * worse than no import at all.
 *
 * ## One row is one product and one variant
 *
 * [ADR 0013](docs/adr/0013-flat-product-import.md). A flat file's name column is one
 * string with everything baked into it — «آیفون ۱۵ پرو مکس ۲۵۶ مشکی» — and this importer
 * never parses it into a product plus a colour plus a storage size. Every row becomes a
 * product and one `options: []` variant carrying the barcode, SKU and price, which is
 * already the house convention for anything without axes.
 *
 * The rationale is an asymmetry rather than a preference: two colours wrongly landing as
 * two products costs an afternoon of tidying and everything still sells, while two
 * products wrongly merged is **permanent** — stock movements and invoice lines reference
 * the variant, and splitting one is not an operation this system has. When one side of a
 * guess is recoverable and the other is not, do not guess.
 *
 * ## Quantity is deliberately not imported
 *
 * Golden rule 3: stock is a ledger, so an opening quantity is a `stock_movements` row
 * needing a warehouse and a unit cost the file does not carry — and for a serialized
 * product it is meaningless, because twelve handsets are twelve `product_units` with
 * twelve IMEIs. The column is still offered on the mapping screen, greyed and labelled,
 * because silence there reads as a bug while a label reads as a decision.
 *
 * @phpstan-type ImportedRow array{
 *     name: string|null,
 *     sku: string|null,
 *     barcode: string|null,
 *     price: int|null,
 *     brand: string|null,
 *     category: string|null,
 *     description: string|null,
 *     rejected_money: array<string, string>,
 * }
 */
final class ProductImporter
{
    /** Columns a sheet can be mapped onto. The key is what the mapping refers to. */
    public const FIELDS = [
        'name' => 'نام کالا',
        'barcode' => 'بارکد',
        'sku' => 'کد کالا',
        'price' => 'قیمت فروش',
        'brand' => 'برند',
        'category' => 'دسته‌بندی',
        'description' => 'توضیحات',
    ];

    /**
     * Columns the screen offers and the importer refuses.
     *
     * Mapped so the operator can see their file was understood, then ignored — with the
     * reason and the correct path shown beside it. An import that quietly drops a column
     * the shop can see in their own file is one they stop trusting.
     */
    public const IGNORED_FIELDS = [
        'quantity' => [
            'label' => 'موجودی',
            'reason' => 'موجودی از این‌جا وارد نمی‌شود.',
            'instead' => 'موجودی اولیه را با «فاکتور خرید» یا «انبارگردانی» ثبت کنید تا در کاردکس بماند.',
        ],
    ];

    public const OUTCOME_CREATE = 'create';

    public const OUTCOME_UPDATE = 'update';

    public const OUTCOME_DUPLICATE = 'duplicate_in_file';

    public const OUTCOME_ERROR = 'error';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SpreadsheetReaders $readers,
        private readonly PriceResolver $prices,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * The header row, a few sample rows, and what we can tell about the file itself.
     *
     * @return array{
     *     headers: list<string>,
     *     samples: list<list<string>>,
     *     total_columns: int,
     *     encoding: string,
     *     repaired_text: bool,
     * }
     */
    public function preview(string $path, string $extension, int $samples = 5): array
    {
        $encoding = Encoding::detectFor($path, $extension);

        $rows = [];

        foreach ($this->readers->forExtension($extension)->rows($path, $samples + 1) as $row) {
            $rows[] = $row;
        }

        $headers = array_shift($rows) ?? [];

        return [
            'headers' => $headers,
            'samples' => $rows,
            'total_columns' => count($headers),
            'encoding' => $encoding,
            'repaired_text' => $this->textWasRepaired($path, $extension, $encoding),
        ];
    }

    /**
     * Walk the sheet and report what would happen, writing nothing.
     *
     * @param  array<string, int|null>  $mapping  field => column index
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function analyse(string $path, string $extension, array $mapping, string $unit, string $type): array
    {
        return $this->walk($path, $extension, $mapping, $unit, $type, commit: false);
    }

    /**
     * Do it. One transaction: a half-imported catalogue is worse than none, because
     * nobody can tell which half.
     *
     * @param  array<string, int|null>  $mapping
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function import(string $path, string $extension, array $mapping, string $unit, string $type): array
    {
        /** @var array{rows: list<array<string, mixed>>, counts: array<string, int>} $result */
        $result = $this->connection->transaction(
            fn (): array => $this->walk($path, $extension, $mapping, $unit, $type, commit: true)
        );

        return $result;
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    private function walk(string $path, string $extension, array $mapping, string $unit, string $type, bool $commit): array
    {
        $reader = $this->readers->forExtension($extension);

        $rows = [];
        $counts = [
            self::OUTCOME_CREATE => 0,
            self::OUTCOME_UPDATE => 0,
            self::OUTCOME_DUPLICATE => 0,
            self::OUTCOME_ERROR => 0,
        ];

        $seenBarcodes = [];
        $seenSkus = [];

        // Resolved once rather than per row: a 2,000-row file would otherwise ask the
        // database for the same brand two thousand times.
        $brands = [];
        $categories = [];

        $level = $this->defaultPriceLevel();

        $lineNumber = 0;
        $columnCount = 0;

        foreach ($reader->rows($path) as $raw) {
            $lineNumber++;

            if ($lineNumber === 1) {
                $columnCount = count($raw);

                continue;
            }

            if ($this->isBlank($raw)) {
                continue;
            }

            /*
            | A row with MORE fields than the header has an unescaped delimiter inside
            | one of its values — and every column after that point is shifted by one.
            |
            | Found on the browser walk, and it is the silent kind: a price cell reading
            | «18,900,000» in a comma-delimited file splits into three, the mapping reads
            | column 2 as «18», and the row imports as a perfectly plausible 18 toman.
            | No error, no empty cell, just a phone priced at eighteen toman.
            |
            | FEWER fields is left alone: a trailing empty column is routinely omitted and
            | shifts nothing, because the values that are present are still in their own
            | columns.
            */
            if ($columnCount > 0 && count($raw) > $columnCount) {
                $rows[] = [
                    'line' => $lineNumber,
                    'name' => $raw[0],
                    'barcode' => null,
                    'price' => null,
                    'outcome' => self::OUTCOME_ERROR,
                    'message' => 'این سطر '.Digits::toPersian((string) count($raw)).' ستون دارد و سطر عنوان '
                        .Digits::toPersian((string) $columnCount)
                        .'. احتمالاً یکی از مقدارها خودش کاما دارد؛ آن را داخل گیومه بگذارید.',
                ];
                $counts[self::OUTCOME_ERROR]++;

                continue;
            }

            $fields = $this->extract($raw, $mapping, $unit);

            // Our own template's example rows. A shop that forgets to delete them must
            // not end up selling «مثال — این سطر را پاک کنید».
            if (ProductImportTemplate::isExample($fields['name'])) {
                continue;
            }

            $row = [
                'line' => $lineNumber,
                'name' => $fields['name'],
                'barcode' => $fields['barcode'],
                'price' => $fields['price'],
            ];

            $error = $this->validate($fields);

            if ($error !== null) {
                $rows[] = $row + ['outcome' => self::OUTCOME_ERROR, 'message' => $error];
                $counts[self::OUTCOME_ERROR]++;

                continue;
            }

            $duplicateOf = $this->duplicateWithinFile($fields, $seenBarcodes, $seenSkus);

            if ($duplicateOf !== null) {
                $rows[] = $row + [
                    'outcome' => self::OUTCOME_DUPLICATE,
                    'message' => 'همین بارکد یا کد کالا در سطر '.Digits::toPersian((string) $duplicateOf).' همین فایل هم هست.',
                ];
                $counts[self::OUTCOME_DUPLICATE]++;

                continue;
            }

            if ($fields['barcode'] !== null) {
                $seenBarcodes[$fields['barcode']] = $lineNumber;
            }

            if ($fields['sku'] !== null) {
                $seenSkus[$fields['sku']] = $lineNumber;
            }

            $existing = $this->findExisting($fields);

            if ($existing instanceof ProductVariant) {
                $rows[] = $row + [
                    'outcome' => self::OUTCOME_UPDATE,
                    'message' => 'با کالای موجود «'.($existing->product instanceof Product ? $existing->product->name : '—').'» تطبیق داده شد.',
                ];
                $counts[self::OUTCOME_UPDATE]++;

                if ($commit) {
                    $this->applyTo($existing, $fields, $level);
                }

                continue;
            }

            $rows[] = $row + [
                'outcome' => self::OUTCOME_CREATE,
                // The one thing a shopkeeper cannot see coming: with no barcode and no
                // SKU this row can never be matched, so importing the file again makes
                // another copy. Said at the row, not buried in a summary.
                'message' => $fields['barcode'] === null && $fields['sku'] === null
                    ? 'بدون بارکد و کد کالا — اگر همین فایل را دوباره وارد کنید، تکراری ساخته می‌شود.'
                    : null,
            ];
            $counts[self::OUTCOME_CREATE]++;

            if ($commit) {
                $this->create($fields, $type, $level, $brands, $categories);
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param  list<string>  $raw
     * @param  array<string, int|null>  $mapping
     * @return ImportedRow
     */
    private function extract(array $raw, array $mapping, string $unit): array
    {
        $value = static function (?int $index) use ($raw): ?string {
            if ($index === null) {
                return null;
            }

            $cell = trim($raw[$index] ?? '');

            return $cell === '' ? null : $cell;
        };

        $rejected = [];

        $money = function (?string $input, string $field) use ($unit, &$rejected): ?int {
            if ($input === null) {
                return null;
            }

            try {
                return Money::parse($this->stripUnitWord($input, $unit), $unit);
            } catch (InvalidArgumentException) {
                $rejected[$field] = $input;

                return null;
            }
        };

        // A barcode is digits, and Excel loves to hand one back as a float or with a
        // stray space. Normalised here so the uniqueness check compares like with like.
        $code = static function (?string $input): ?string {
            if ($input === null) {
                return null;
            }

            $digits = preg_replace('/\s+/', '', Digits::toLatin($input)) ?? '';

            return $digits === '' ? null : $digits;
        };

        return [
            'name' => $value($mapping['name'] ?? null),
            'sku' => $code($value($mapping['sku'] ?? null)),
            'barcode' => $code($value($mapping['barcode'] ?? null)),
            'price' => $money($value($mapping['price'] ?? null), 'price'),
            'brand' => $value($mapping['brand'] ?? null),
            'category' => $value($mapping['category'] ?? null),
            'description' => $value($mapping['description'] ?? null),
            'rejected_money' => $rejected,
        ];
    }

    /**
     * Remove a currency word the cell states itself, and refuse one that contradicts.
     *
     * A sheet writes «۱۲۵۰۰۰۰ تومان» often enough that rejecting it outright would lose
     * ordinary rows. But a cell saying تومان while the operator chose ریال is not noise:
     * it is the operator having picked the wrong unit for the **whole file**, and it is
     * worth ten times every price in it. So the agreeing word is dropped and the
     * contradicting one is an error.
     */
    private function stripUnitWord(string $input, string $unit): string
    {
        $words = [Money::UNIT_TOMAN => ['تومان', 'تومن'], Money::UNIT_RIAL => ['ریال', '﷼']];

        foreach ($words as $for => $spellings) {
            foreach ($spellings as $spelling) {
                if (! str_contains($input, $spelling)) {
                    continue;
                }

                if ($for !== $unit) {
                    throw new InvalidArgumentException("Cell states [{$spelling}] but the file was declared as [{$unit}].");
                }

                $input = str_replace($spelling, '', $input);
            }
        }

        return $input;
    }

    /**
     * @param  ImportedRow  $fields
     */
    private function validate(array $fields): ?string
    {
        if (! is_string($fields['name']) || $fields['name'] === '') {
            return 'نام کالا خالی است.';
        }

        // A price nobody could read is an error, never a zero. A zero price is a real
        // price — it would go on the shelf and out the door at nothing.
        if ($fields['rejected_money'] !== []) {
            $bad = $fields['rejected_money']['price'] ?? '';

            return 'قیمت «'.$bad.'» خوانده نشد. عدد را بدون حرف و با یک جداکنندهٔ اعشار بنویسید.';
        }

        if ($fields['barcode'] !== null && strlen($fields['barcode']) > 64) {
            return 'بارکد بلندتر از حد مجاز است.';
        }

        return null;
    }

    /**
     * @param  ImportedRow  $fields
     * @param  array<array-key, int>  $seenBarcodes
     * @param  array<array-key, int>  $seenSkus
     */
    private function duplicateWithinFile(array $fields, array $seenBarcodes, array $seenSkus): ?int
    {
        $barcode = $fields['barcode'];

        if ($barcode !== null && isset($seenBarcodes[$barcode])) {
            return $seenBarcodes[$barcode];
        }

        $sku = $fields['sku'];

        if ($sku !== null && isset($seenSkus[$sku])) {
            return $seenSkus[$sku];
        }

        return null;
    }

    /**
     * Barcode first, SKU second — the ladder from ADR 0013.
     *
     * Both are unique per tenant among live rows (partial unique indexes), so both are
     * safe keys. A row with neither cannot be matched at all, which is why that case is
     * called out on its own row rather than left to be discovered on the second import.
     *
     * @param  ImportedRow  $fields
     */
    private function findExisting(array $fields): ?ProductVariant
    {
        $barcode = $fields['barcode'];

        if ($barcode !== null) {
            $byBarcode = ProductVariant::query()->with('product')->where('barcode', $barcode)->first();

            if ($byBarcode instanceof ProductVariant) {
                return $byBarcode;
            }
        }

        $sku = $fields['sku'];

        if ($sku !== null) {
            $bySku = ProductVariant::query()->with('product')->where('sku', $sku)->first();

            if ($bySku instanceof ProductVariant) {
                return $bySku;
            }
        }

        return null;
    }

    /**
     * @param  ImportedRow  $fields
     * @param  array<string, int>  $brands
     * @param  array<string, int>  $categories
     */
    private function create(array $fields, string $type, PriceLevel $level, array &$brands, array &$categories): void
    {
        /** @var Product $product */
        $product = Product::query()->create([
            'name' => $fields['name'],
            'sku' => $fields['sku'],
            'type' => $type,
            'description' => $fields['description'],
            'brand_id' => $this->brandId($fields['brand'], $brands),
            'category_id' => $this->categoryId($fields['category'], $categories),
            'is_active' => true,
        ]);

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            // The no-axis variant. Everything that can happen to this product — stock,
            // a sale, a price — happens to this row, because nothing in the system
            // references a product directly.
            'options' => [],
            'sku' => $fields['sku'],
            'barcode' => $fields['barcode'],
            'is_active' => true,
        ]);

        if ($fields['price'] !== null) {
            $this->prices->setPrice($variant->id, $level->id, $fields['price']);
        }
    }

    /**
     * Fill gaps on a product the shop already has; never overwrite what is there.
     *
     * The sheet is an import, not a source of truth — a name corrected in the app last
     * week must not be undone by a stale export. The price is the deliberate exception:
     * it is append-only history, so a new row is a *new price from now*, which is what
     * re-importing a price list means.
     *
     * @param  ImportedRow  $fields
     */
    private function applyTo(ProductVariant $variant, array $fields, PriceLevel $level): void
    {
        $product = $variant->product;

        if ($product instanceof Product) {
            $updates = [];

            if ($product->description === null && $fields['description'] !== null) {
                $updates['description'] = $fields['description'];
            }

            if ($updates !== []) {
                $product->update($updates);
            }
        }

        $variantUpdates = [];

        foreach (['sku', 'barcode'] as $column) {
            if ($variant->getAttribute($column) === null && $fields[$column] !== null) {
                $variantUpdates[$column] = $fields[$column];
            }
        }

        if ($variantUpdates !== []) {
            $variant->update($variantUpdates);
        }

        if ($fields['price'] !== null) {
            $this->prices->setPrice($variant->id, $level->id, $fields['price']);
        }
    }

    /**
     * @param  array<string, int>  $cache
     */
    private function brandId(?string $name, array &$cache): ?int
    {
        if ($name === null) {
            return null;
        }

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        /** @var Brand $brand */
        $brand = Brand::query()->firstOrCreate(['name' => $name]);

        return $cache[$name] = $brand->id;
    }

    /**
     * @param  array<string, int>  $cache
     */
    private function categoryId(?string $name, array &$cache): ?int
    {
        if ($name === null) {
            return null;
        }

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $existing = Category::query()->where('name', $name)->first();

        if ($existing instanceof Category) {
            return $cache[$name] = $existing->id;
        }

        /** @var Category $category */
        $category = Category::query()->create([
            'name' => $name,
            'slug' => $this->categories->slugFor($name),
        ]);

        return $cache[$name] = $category->id;
    }

    private function defaultPriceLevel(): PriceLevel
    {
        $level = PriceLevel::query()->where('is_default', true)->first()
            ?? PriceLevel::query()->orderBy('position')->first();

        if (! $level instanceof PriceLevel) {
            throw new InvalidArgumentException('This shop has no price levels; cannot import prices.');
        }

        return $level;
    }

    /**
     * Whether reading this file changed any of its text.
     *
     * Reported so the repair is announced rather than performed silently — the operator
     * is told the file went through a legacy code page and the sample rows are the
     * evidence they check before continuing.
     */
    private function textWasRepaired(string $path, string $extension, string $encoding): bool
    {
        if ($encoding === Encoding::WINDOWS_1256) {
            return true;
        }

        /*
        | Asked of the RAW file, never of the reader's output.
        |
        | The readers normalise as they parse — that is what makes four file formats
        | produce identical rows — so by the time a row reaches this class the repair has
        | already happened and `needsRepair` is false on every cell. Asking there
        | reported "nothing changed" on precisely the files that were changed most, which
        | is the announcement being wrong in the one direction that matters.
        |
        | Only a text format has raw text to inspect. A workbook typed on an Arabic-locale
        | keyboard is still repaired; it just gets no badge, because the badge exists to
        | explain a change the operator can see in the sample rows and the code-page case
        | is where visible change happens.
        */
        if (! in_array(strtolower($extension), ['csv', 'txt'], true)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $sample = (string) fread($handle, 65536);
        } finally {
            fclose($handle);
        }

        return PersianText::needsRepair(Encoding::toUtf8($sample, $encoding));
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Guess a mapping from the header row.
     *
     * Gets it wrong harmlessly: every guess is shown on the mapping screen before
     * anything is read. The hints are ordered so that a more specific one wins — «کد
     * کالا» must not be taken by the «کد» in «بارکد».
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function guessMapping(array $headers): array
    {
        $hints = [
            'barcode' => ['بارکد', 'بار کد', 'barcode', 'ean', 'upc'],
            'sku' => ['کد کالا', 'کدکالا', 'کد جنس', 'شناسه کالا', 'sku', 'code'],
            'name' => ['نام کالا', 'شرح کالا', 'نام جنس', 'عنوان', 'شرح', 'نام', 'name', 'title', 'product'],
            'price' => ['قیمت فروش', 'قيمت فروش', 'مبلغ فروش', 'قیمت', 'قيمت', 'مبلغ', 'price', 'amount'],
            'brand' => ['برند', 'سازنده', 'مارک', 'brand', 'maker'],
            'category' => ['دسته', 'دسته‌بندی', 'دسته بندی', 'گروه', 'گروه کالا', 'category', 'group'],
            'description' => ['توضیح', 'توضیحات', 'شرح تکمیلی', 'description', 'note'],
            'quantity' => ['موجودی', 'تعداد', 'مقدار', 'quantity', 'qty', 'stock'],
        ];

        $mapping = [];
        $taken = [];

        foreach ($hints as $field => $needles) {
            $mapping[$field] = null;

            foreach ($needles as $needle) {
                foreach ($headers as $index => $header) {
                    if (in_array($index, $taken, true)) {
                        continue;
                    }

                    $normalised = mb_strtolower(PersianText::normalise(trim($header)));

                    if ($normalised !== '' && str_contains($normalised, mb_strtolower(PersianText::normalise($needle)))) {
                        $mapping[$field] = $index;
                        $taken[] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }
}
