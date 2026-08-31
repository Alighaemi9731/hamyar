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
  /**
   * Marks the field as rejected, exactly like any other input.
   *
   * Without this the one field carrying a *numeric* refusal — «مبلغ نباید کمتر از ۱
   * باشد» — was the only control on its form with no red border, while the selects
   * beside it lit up correctly. A field that states an error and looks fine is the
   * silent-failure bug in miniature.
   */
  'aria-invalid'?: boolean;
  'aria-describedby'?: string;
  id?: string;
  placeholder?: string;
  className?: string;
}

/**
 * An amount, typed by a human.
 *
 * Lives in the shared domain kit rather than in Sales: the till types prices, the repair
 * delivery screen types labour charges, and Treasury will type receipts. One money input
 * with one set of rules about grouping, digits and units — the same reasoning as
 * `<Money/>` being the only way money is rendered (design-system rule 5).
 *
 * ## Grouped at rest, raw once you start typing
 *
 * `82000000` and `82,000,000` are the same number and only one of them can be read at a
 * glance. On a till, misreading a price by a factor of ten is the most expensive mistake
 * the screen can invite, so the separators have to be there.
 *
 * They cannot be there *while typing*, though: re-grouping on every keystroke moves the
 * caret to the end of the field, so correcting the middle of a number becomes
 * impossible. So the box holds grouped digits until the first keystroke, shows raw
 * digits from then on, and re-groups on blur — the same trade the Catalog price grid
 * makes, for the same reason.
 *
 * Note what does NOT happen: the value is untouched on **focus**. Rewriting it there
 * re-renders the input while the browser has a selection in it, and a re-render
 * collapses that selection — so typing over a selected figure appends to it instead of
 * replacing it. See the comment on `onFocus`.
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
  'aria-invalid': ariaInvalid,
  'aria-describedby': ariaDescribedBy,
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
      aria-invalid={ariaInvalid}
      aria-describedby={ariaDescribedBy}
      placeholder={placeholder}
      className={cn('tabular', className)}
      value={draft}
      // Focus records that somebody is editing — and deliberately does NOT touch the
      // value. Stripping the separators here re-rendered the input mid-focus, which
      // collapses the browser's selection to the end of the field: typing over a
      // selected "65,000,000" then appended instead of replacing it, and the payment
      // box showed 6,500,000,015,000,000. Found in a browser pass, not by a test.
      //
      // It also defeated its own purpose. The point was to let the caret sit anywhere
      // in the number, but changing the value on focus moves the caret to the end,
      // which is exactly what it was trying to avoid. The Catalog price grid never had
      // an onFocus for the same reason; this now matches it.
      onFocus={() => setFocused(true)}
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
