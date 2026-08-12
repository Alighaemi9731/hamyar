import { useEffect, useState } from 'react';

import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';

const RIAL_PER_TOMAN = 10;

interface MoneyFieldProps {
  /** Integer rial (golden rule 2). The field renders it in the shop's display unit. */
  value: number;
  onChange: (rial: number) => void;
  /** True when the shop displays toman — almost always. */
  toman: boolean;
  'aria-label'?: string;
  id?: string;
  placeholder?: string;
  className?: string;
}

/**
 * An amount, typed at the till.
 *
 * ## Grouped when you are reading it, raw while you are typing
 *
 * `82000000` and `82,000,000` are the same number and only one of them can be read at a
 * glance. On a till, misreading a price by a factor of ten is the most expensive mistake
 * the screen can invite, so the separators have to be there.
 *
 * They cannot be there *while typing*, though: reformatting on every keystroke moves the
 * caret to the end of the field, so correcting the middle of a number becomes
 * impossible. So the box shows grouped digits at rest and drops to raw on focus — the
 * same trade the Catalog price grid makes, for the same reason.
 *
 * ## The value changes on every keystroke anyway
 *
 * Unlike that grid, which saves on blur, this reports upward immediately: the POS total
 * is being read out to a customer while the discount is still being typed, and a total
 * that waits for a blur is a total that is wrong at the moment somebody is looking at it.
 *
 * ## Storage is rial, entry is toman
 *
 * Converted in exactly one place — here — because a field that stores rial while showing
 * toman is how a shop sells a phone for a tenth of its price.
 */
export function MoneyField({
  value,
  onChange,
  toman,
  id,
  placeholder,
  className,
  'aria-label': ariaLabel,
}: MoneyFieldProps) {
  const factor = toman ? RIAL_PER_TOMAN : 1;

  const grouped = (rial: number): string =>
    rial === 0 ? '' : Math.trunc(rial / factor).toLocaleString('en-US');

  const [draft, setDraft] = useState(() => grouped(value));
  const [focused, setFocused] = useState(false);

  // A value changed from outside — a payment row pre-filled with what is left owing, a
  // resumed draft — has to reach the box. Skipped while focused so it cannot overwrite
  // what somebody is mid-way through typing.
  useEffect(() => {
    if (!focused) {
      setDraft(grouped(value));
    }
    // `grouped` is re-created every render; keying on the inputs it depends on instead.
  }, [value, focused, factor]);

  return (
    <Input
      id={id}
      dir="ltr"
      inputMode="numeric"
      autoComplete="off"
      aria-label={ariaLabel}
      placeholder={placeholder}
      className={cn('tabular', className)}
      value={draft}
      onFocus={(event) => {
        setFocused(true);
        // Raw while editing, so the caret can sit anywhere in the number.
        setDraft(toLatinDigits(event.target.value).replace(/[^\d]/g, ''));
      }}
      onBlur={() => {
        setFocused(false);
        setDraft(grouped(value));
      }}
      onChange={(event) => {
        const digits = toLatinDigits(event.target.value).replace(/[^\d]/g, '');

        setDraft(digits);
        onChange(digits === '' ? 0 : Number(digits) * factor);
      }}
    />
  );
}
