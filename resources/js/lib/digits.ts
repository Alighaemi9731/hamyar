const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] as const;
const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'] as const;
const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'] as const;

const LATIN_SEPARATOR = ',';
const PERSIAN_SEPARATOR = '٬';

/**
 * Mirror of App\Support\Digits. Both sides must agree, because a number formatted
 * on the server and one formatted in the browser sit next to each other on screen.
 */
export function toPersianDigits(value: string): string {
  return value.replace(/[0-9,]/g, (char) =>
    char === LATIN_SEPARATOR ? PERSIAN_SEPARATOR : (PERSIAN[Number(char)] ?? char)
  );
}

/**
 * Normalise whatever the user typed — Persian or Arabic-Indic digits are produced
 * interchangeably by Iranian and Arabic-locale keyboards — down to Latin digits,
 * so validation and the wire format only ever see one form.
 */
export function toLatinDigits(value: string): string {
  return value.replace(/[۰-۹٠-٩٬]/g, (char) => {
    if (char === PERSIAN_SEPARATOR) return LATIN_SEPARATOR;

    const persian = PERSIAN.indexOf(char as (typeof PERSIAN)[number]);
    if (persian !== -1) return LATIN[persian] as string;

    const arabic = ARABIC.indexOf(char as (typeof ARABIC)[number]);
    if (arabic !== -1) return LATIN[arabic] as string;

    return char;
  });
}
