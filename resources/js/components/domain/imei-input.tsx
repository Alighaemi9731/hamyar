import { CheckCircle2Icon, XCircleIcon } from 'lucide-react';
import { useId, useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLatinDigits, toPersianDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';

export interface ImeiInputProps {
  value: string;
  onChange: (value: string) => void;
  label?: string;
  /** Server-side error, shown in place of the live hint. */
  error?: string;
  optional?: boolean;
  disabled?: boolean;
  autoFocus?: boolean;
  className?: string;
}

/**
 * Strips separators and converts Persian/Arabic digits to Latin.
 *
 * Mirrors `App\Support\Imei::normalise()`. Both ends normalise because the client must
 * validate before submitting and the server must never trust that it did.
 */
export function normaliseImei(input: string): string {
  return toLatinDigits(input).replace(/\D+/g, '');
}

/**
 * Luhn check over 15 digits — the same rule as `App\Support\Imei::isValid()`.
 *
 * Duplicated deliberately rather than round-tripped to the server: at intake someone is
 * typing or scanning twenty of these in a row, and a per-keystroke request would make
 * the field feel broken on a shop's connection.
 */
export function isValidImei(input: string): boolean {
  const digits = normaliseImei(input);

  if (digits.length !== 15 || digits === '0'.repeat(15)) {
    return false;
  }

  let sum = 0;
  let double = false;

  for (let i = digits.length - 1; i >= 0; i--) {
    let value = Number(digits[i]);

    if (double) {
      value *= 2;
      if (value > 9) value -= 9;
    }

    sum += value;
    double = !double;
  }

  return sum % 10 === 0;
}

/**
 * The field the whole product turns on.
 *
 * Three things it does that a plain `<Input/>` does not:
 *
 * 1. **Accepts Persian digits and separators.** Staff type on Persian keyboards and
 *    paste from Persian documents; `۳۵۶۹۳۸-۰۳۵۶۴۳۸۰۹` has to work.
 * 2. **Renders LTR while keeping the label RTL.** An IMEI read right-to-left cannot be
 *    read back to a customer or typed into HAMTA (design-system rule 3).
 * 3. **Validates as you type, and says so.** A mistyped IMEI accepted at intake is
 *    invisible until the phone is sold or warranty-claimed, by which point the
 *    paperwork trail is broken.
 */
export function ImeiInput({
  value,
  onChange,
  label = 'شماره IMEI',
  error,
  optional = false,
  disabled = false,
  autoFocus = false,
  className,
}: ImeiInputProps) {
  const id = useId();
  const digits = useMemo(() => normaliseImei(value), [value]);

  const complete = digits.length === 15;
  // A server error outranks local validity. The number can be a perfectly formed IMEI
  // and still be rejected — already registered to another device, most often — and a
  // green tick next to a red error message tells the operator two opposite things.
  const valid = complete && isValidImei(digits) && !error;
  const showInvalid = complete && !valid;

  const describedBy = `${id}-hint`;

  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor={id}>
        {label}
        {optional ? <span className="ms-1 text-muted-foreground">(اختیاری)</span> : null}
      </Label>

      {/*
        dir="ltr" on the WRAPPER, not just the input.
        The icon is positioned with `end-3`, which resolves against its containing
        block. Left on an RTL page — the side where LTR digits start — so it landed on
        top of the number while the input reserved its padding on the other side. A
        browser check caught it; the logical utilities were right, the direction context
        was not.
      */}
      <div className="relative" dir="ltr">
        <Input
          id={id}
          // LTR content, RTL layout: the field sits in a right-to-left form but its
          // digits read left-to-right like the sticker on the box.
          dir="ltr"
          inputMode="numeric"
          autoComplete="off"
          autoFocus={autoFocus}
          disabled={disabled}
          maxLength={20}
          value={value}
          aria-invalid={showInvalid || Boolean(error)}
          aria-describedby={describedBy}
          onChange={(event) => onChange(event.target.value)}
          className={cn(
            'tabular pe-9',
            showInvalid || error ? 'border-danger focus-visible:ring-danger/30' : null,
            valid ? 'border-success' : null
          )}
          placeholder="356938035643809"
        />

        {complete ? (
          <span className="absolute inset-y-0 end-3 flex items-center" aria-hidden>
            {valid ? (
              <CheckCircle2Icon className="size-5 text-success" />
            ) : (
              <XCircleIcon className="size-5 text-danger" />
            )}
          </span>
        ) : null}
      </div>

      <p
        id={describedBy}
        className={cn('text-sm', error || showInvalid ? 'text-danger' : 'text-muted-foreground')}
      >
        {error
          ? error
          : showInvalid
            ? 'این شماره IMEI معتبر نیست. رقم کنترلی نمی‌خواند — دوباره بررسی کنید.'
            : complete
              ? 'شماره معتبر است.'
              : // Persian digits: this is prose, not a table (design-system rule 4).
                `۱۵ رقم. تاکنون ${toPersianDigits(String(digits.length))} رقم وارد شده.`}
      </p>
    </div>
  );
}
