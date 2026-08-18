<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Support\Spreadsheet\ArraySheet;

/**
 * The blank sheet a shop can fill in instead of reshaping the one they have.
 *
 * The mapping screen exists because most shops arrive with an export they cannot change.
 * This is for everyone else — a shop starting fresh, or one whose export is so far from
 * usable that retyping is quicker. Both paths land in the same importer.
 *
 * ## It ships with example rows, and they are removed on import
 *
 * A template with only headers gets filled in wrongly: nobody can tell whether «قیمت»
 * wants toman or rial, whether a barcode may be blank, or what a category looks like. Two
 * filled rows answer all three at a glance. They are marked with a leading `#` in the
 * name so the importer skips them — a shop that forgets to delete the examples does not
 * import «مثال: گوشی موبایل» as a product.
 */
final class ProductImportTemplate
{
    /** Rows whose name starts with this are examples, not data. */
    public const EXAMPLE_MARKER = '#';

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = array_values(ProductImporter::FIELDS);

        foreach (ProductImporter::IGNORED_FIELDS as $ignored) {
            // Present, and labelled as ignored right in the header, so the column a shop
            // certainly has does not look like an omission on our side.
            $headings[] = $ignored['label'].' (وارد نمی‌شود)';
        }

        return $headings;
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function exampleRows(): array
    {
        return [
            [
                self::EXAMPLE_MARKER.' مثال — این سطر را پاک کنید',
                '6260000000019',
                'ACC-CHG-20W',
                '450000',
                'اپل',
                'لوازم جانبی',
                'شارژر ۲۰ وات اورجینال',
                '12',
            ],
            [
                self::EXAMPLE_MARKER.' مثال — بارکد و کد کالا می‌توانند خالی باشند',
                '',
                '',
                '189000000',
                'سامسونگ',
                'گوشی موبایل',
                'بدون بارکد، هنگام ورود دوباره تکراری ساخته می‌شود',
                '3',
            ],
        ];
    }

    public function sheet(): ArraySheet
    {
        return new ArraySheet($this->headings(), $this->exampleRows());
    }

    /**
     * Whether a row from a filled-in template is one of our own examples.
     */
    public static function isExample(?string $name): bool
    {
        return $name !== null && str_starts_with(ltrim($name), self::EXAMPLE_MARKER);
    }
}
