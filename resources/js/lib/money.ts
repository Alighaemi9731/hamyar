import { toLatinDigits, toPersianDigits } from '@/lib/digits';

export type CurrencyUnit = 'rial' | 'toman';

export const RIAL_PER_TOMAN = 10;

/**
 * Client-side mirror of App\Support\Money.
 *
 * Golden rule 2: amounts are integer rial. Nothing here divides in a way that can
 * produce a float, and the client never *computes* a total that the server will
 * store — it only formats what the server sent.
 */

export function formatMoney(
  rial: number,
  unit: CurrencyUnit = 'rial',
  persianDigits = false
): string {
  const amount = unit === 'toman' ? toTomanOrThrow(rial) : rial;

  const negative = amount < 0;
  // Group the absolute value so the minus sits outside the digit grouping.
  const digits = Math.abs(amount).toLocaleString('en-US');

  const formatted = `${negative ? '-' : ''}${digits}`;

  return persianDigits ? toPersianDigits(formatted) : formatted;
}

export function formatMoneyWithUnit(
  rial: number,
  unit: CurrencyUnit = 'rial',
  persianDigits = false
): string {
  return `${formatMoney(rial, unit, persianDigits)} ${unit === 'toman' ? 'تومان' : 'ریال'}`;
}

function toTomanOrThrow(rial: number): number {
  if (rial % RIAL_PER_TOMAN !== 0) {
    // Same refusal as the server: silently rounding here would show the customer a
    // different total from the one on the invoice.
    throw new Error(`${rial} rial is not a whole number of toman; refusing to round.`);
  }

  return rial / RIAL_PER_TOMAN;
}

/**
 * Parse user input (Persian or Latin digits, with or without separators) into rial.
 * Returns null rather than throwing, so form fields can show a validation message.
 */
export function parseMoney(input: string, unit: CurrencyUnit = 'rial'): number | null {
  const normalised = toLatinDigits(input.trim()).replace(/[,\s‌]/g, '');

  if (normalised === '' || !/^-?\d+$/.test(normalised)) {
    return null;
  }

  const value = Number(normalised);

  return unit === 'toman' ? value * RIAL_PER_TOMAN : value;
}
