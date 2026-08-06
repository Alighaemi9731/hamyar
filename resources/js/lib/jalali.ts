import * as jalaali from 'jalaali-js';

import { toLatinDigits, toPersianDigits } from '@/lib/digits';

/**
 * Jalali (Solar Hijri) date maths for the client.
 *
 * Mirrors App\Support\Jalali: values crossing the wire are UTC ISO strings, and the
 * conversion is always UTC → Asia/Tehran → Jalali, in that order. Skipping the
 * timezone step reports the wrong *day* for anything after 20:30 Tehran time.
 *
 * `Intl` does the timezone shift rather than a hardcoded +03:30, because Iran has
 * changed its DST rules more than once and the browser's tz database is current.
 */

export const DISPLAY_TIMEZONE = 'Asia/Tehran';

export const MONTH_NAMES = [
  'فروردین',
  'اردیبهشت',
  'خرداد',
  'تیر',
  'مرداد',
  'شهریور',
  'مهر',
  'آبان',
  'آذر',
  'دی',
  'بهمن',
  'اسفند',
] as const;

/** Saturday-first, matching the Iranian week. */
export const WEEKDAY_NAMES = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as const;

export interface JalaliDate {
  jy: number;
  jm: number;
  jd: number;
}

/** Wall-clock parts of an instant in the shop's timezone. */
function tehranParts(date: Date): { y: number; m: number; d: number; hh: number; mm: number } {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone: DISPLAY_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });

  const parts = Object.fromEntries(
    formatter.formatToParts(date).map((part) => [part.type, part.value])
  );

  return {
    y: Number(parts.year),
    m: Number(parts.month),
    d: Number(parts.day),
    // Intl renders midnight as "24" in some engines; normalise it.
    hh: Number(parts.hour) % 24,
    mm: Number(parts.minute),
  };
}

export function toJalali(value: Date | string): JalaliDate {
  const date = typeof value === 'string' ? new Date(value) : value;
  const { y, m, d } = tehranParts(date);

  return jalaali.toJalaali(y, m, d);
}

/**
 * Jalali calendar date → the UTC instant at Tehran midnight of that day.
 * This is what a date-only form field submits.
 */
export function jalaliToUtcISO(jy: number, jm: number, jd: number): string {
  const { gy, gm, gd } = jalaali.toGregorian(jy, jm, jd);

  // Tehran is UTC+03:30 with no DST since 2022; encoding the offset explicitly keeps
  // this a pure function rather than depending on the runtime's local timezone.
  const iso = `${pad(gy, 4)}-${pad(gm)}-${pad(gd)}T00:00:00+03:30`;

  return new Date(iso).toISOString();
}

export function formatJalali(
  value: Date | string | null | undefined,
  options: { withTime?: boolean; persianDigits?: boolean; longMonth?: boolean } = {}
): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const { withTime = false, persianDigits = true, longMonth = false } = options;

  const date = typeof value === 'string' ? new Date(value) : value;

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const { jy, jm, jd } = toJalali(date);

  let out = longMonth
    ? `${jd} ${MONTH_NAMES[jm - 1]} ${jy}`
    : `${jy}/${pad(jm)}/${pad(jd)}`;

  if (withTime) {
    const { hh, mm } = tehranParts(date);
    out += ` ${pad(hh)}:${pad(mm)}`;
  }

  return persianDigits ? toPersianDigits(out) : out;
}

/**
 * Parse "۱۴۰۵/۰۵/۱۵" or "1405/05/15". Returns null on anything else so a form can
 * show a message instead of guessing at the user's intent.
 */
export function parseJalali(input: string): JalaliDate | null {
  const match = /^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})$/.exec(toLatinDigits(input.trim()));

  if (!match) {
    return null;
  }

  const jy = Number(match[1]);
  const jm = Number(match[2]);
  const jd = Number(match[3]);

  // Rejects 1405/13/01 and 1405/12/31 in a common year.
  if (!jalaali.isValidJalaaliDate(jy, jm, jd)) {
    return null;
  }

  return { jy, jm, jd };
}

export function todayJalali(): JalaliDate {
  return toJalali(new Date());
}

export function daysInJalaliMonth(jy: number, jm: number): number {
  return jalaali.jalaaliMonthLength(jy, jm);
}

/**
 * Weekday index of the 1st of a Jalali month, Saturday = 0 (Iranian week start).
 */
export function firstWeekdayOfMonth(jy: number, jm: number): number {
  const { gy, gm, gd } = jalaali.toGregorian(jy, jm, 1);
  const jsDay = new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay(); // Sunday = 0

  return (jsDay + 1) % 7; // shift so Saturday = 0
}

export function addJalaliMonths(jy: number, jm: number, delta: number): { jy: number; jm: number } {
  const total = jy * 12 + (jm - 1) + delta;

  return { jy: Math.floor(total / 12), jm: (total % 12) + 1 };
}

function pad(value: number, length = 2): string {
  return String(value).padStart(length, '0');
}
