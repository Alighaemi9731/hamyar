import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  MONTH_NAMES,
  WEEKDAY_NAMES,
  addJalaliMonths,
  daysInJalaliMonth,
  firstWeekdayOfMonth,
  formatJalali,
  jalaliToUtcISO,
  parseJalali,
  toJalali,
  todayJalali,
} from '@/lib/jalali';
import { toPersianDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';

export interface JDatePickerProps {
  /** UTC ISO string, or null. This is what goes to the server (golden rule 5). */
  value: string | null;
  onChange: (utcISO: string | null) => void;
  placeholder?: string;
  disabled?: boolean;
  /** Renders the trigger in an error state; the message itself goes under the field. */
  invalid?: boolean;
  clearable?: boolean;
  id?: string;
  className?: string;
}

/**
 * Jalali date picker.
 *
 * In / out is always a **UTC ISO string** — Jalali is a rendering, never a stored
 * value. Selecting a day yields the instant at Tehran midnight of that day, which is
 * what every date-only field in the product means (an invoice dated 1405/05/15 is
 * that Tehran day, regardless of the reader's timezone).
 *
 * The grid is Saturday-first, and navigation arrows point the RTL way: "previous
 * month" sits on the right, under the reader's start-side hand.
 */
export function JDatePicker({
  value,
  onChange,
  placeholder = 'انتخاب تاریخ',
  disabled = false,
  invalid = false,
  clearable = true,
  id,
  className,
}: JDatePickerProps) {
  const selected = useMemo(() => (value ? toJalali(value) : null), [value]);
  const today = useMemo(() => todayJalali(), []);

  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState('');
  const [cursor, setCursor] = useState(() => ({
    jy: selected?.jy ?? today.jy,
    jm: selected?.jm ?? today.jm,
  }));

  const dayCount = daysInJalaliMonth(cursor.jy, cursor.jm);
  const leadingBlanks = firstWeekdayOfMonth(cursor.jy, cursor.jm);

  function select(day: number) {
    onChange(jalaliToUtcISO(cursor.jy, cursor.jm, day));
    setTyped('');
    setOpen(false);
  }

  function commitTyped() {
    const parsed = parseJalali(typed);

    if (parsed) {
      onChange(jalaliToUtcISO(parsed.jy, parsed.jm, parsed.jd));
      setCursor({ jy: parsed.jy, jm: parsed.jm });
      setTyped('');
      setOpen(false);
    }
  }

  const typedIsInvalid = typed !== '' && parseJalali(typed) === null;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          disabled={disabled}
          aria-invalid={invalid || undefined}
          className={cn(
            'w-full justify-between font-normal',
            !value && 'text-muted-foreground',
            invalid && 'border-destructive',
            className
          )}
        >
          <span className="tabular">{value ? formatJalali(value) : placeholder}</span>
          <CalendarIcon className="size-4 shrink-0 opacity-60" />
        </Button>
      </PopoverTrigger>

      {/* Radix portals render outside the RTL root, so `dir` is explicit here
          (design-system rule 2). */}
      <PopoverContent dir="rtl" align="start" className="w-[19rem] p-3">
        <div className="mb-3 space-y-1">
          <Input
            dir="ltr"
            inputMode="numeric"
            placeholder="۱۴۰۵/۰۵/۱۵"
            value={typed}
            aria-label="تاریخ را تایپ کنید"
            aria-invalid={typedIsInvalid || undefined}
            onChange={(event) => setTyped(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                commitTyped();
              }
            }}
            className={cn('ltr-value text-center tabular', typedIsInvalid && 'border-destructive')}
          />
          {typedIsInvalid && (
            <p className="text-2xs text-destructive">تاریخ معتبر نیست. قالب درست: ۱۴۰۵/۰۵/۱۵</p>
          )}
        </div>

        <div className="mb-2 flex items-center justify-between">
          {/* RTL: "previous" is the right-pointing chevron. */}
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label="ماه بعد"
            onClick={() => setCursor(addJalaliMonths(cursor.jy, cursor.jm, 1))}
          >
            <ChevronLeftIcon className="size-4" />
          </Button>

          <span className="font-display text-xs font-bold">
            {MONTH_NAMES[cursor.jm - 1]} {toPersianDigits(String(cursor.jy))}
          </span>

          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label="ماه قبل"
            onClick={() => setCursor(addJalaliMonths(cursor.jy, cursor.jm, -1))}
          >
            <ChevronRightIcon className="size-4" />
          </Button>
        </div>

        <div className="grid grid-cols-7 gap-0.5 text-center">
          {WEEKDAY_NAMES.map((day, index) => (
            <div
              key={day}
              className={cn(
                'py-1 text-2xs font-medium text-muted-foreground',
                // Friday is the Iranian weekend.
                index === 6 && 'text-destructive/70'
              )}
            >
              {day}
            </div>
          ))}

          {Array.from({ length: leadingBlanks }, (_, index) => (
            <div key={`blank-${index}`} />
          ))}

          {Array.from({ length: dayCount }, (_, index) => {
            const day = index + 1;
            const isSelected =
              selected?.jy === cursor.jy && selected.jm === cursor.jm && selected.jd === day;
            const isToday = today.jy === cursor.jy && today.jm === cursor.jm && today.jd === day;

            return (
              <button
                key={day}
                type="button"
                onClick={() => select(day)}
                aria-current={isToday ? 'date' : undefined}
                aria-pressed={isSelected}
                className={cn(
                  'tabular flex h-9 items-center justify-center rounded-control text-xs transition-colors',
                  'hover:bg-accent hover:text-accent-foreground',
                  isToday && !isSelected && 'ring-1 ring-ring',
                  isSelected && 'bg-primary text-primary-foreground hover:bg-primary'
                )}
              >
                {toPersianDigits(String(day))}
              </button>
            );
          })}
        </div>

        <div className="mt-3 flex items-center justify-between gap-2 border-t border-border pt-3">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => {
              setCursor({ jy: today.jy, jm: today.jm });
              onChange(jalaliToUtcISO(today.jy, today.jm, today.jd));
              setOpen(false);
            }}
          >
            امروز
          </Button>

          {clearable && value && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-muted-foreground"
              onClick={() => {
                onChange(null);
                setOpen(false);
              }}
            >
              پاک کردن
            </Button>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
