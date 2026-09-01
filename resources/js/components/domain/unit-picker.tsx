import { AlertTriangleIcon, ScanLineIcon, SmartphoneIcon, XIcon } from 'lucide-react';
import { type KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PickerSkeleton } from '@/components/domain/picker-skeleton';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { type SearchFn, endpointSearch, useRemoteSearch } from '@/hooks/use-remote-search';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

export interface UnitOption {
  id: number;
  imei1: string | null;
  imei2: string | null;
  serial: string | null;
  product_name: string;
  variant_name: string;
  status: string;
  condition_label: string;
  grade: string | null;
  warehouse_name: string | null;
  /** Null when the signed-in user lacks `inventory.view_cost`. */
  cost: MoneyValue | null;
}

export interface UnitPickerProps {
  value: UnitOption | null;
  onChange: (unit: UnitOption | null) => void;
  /** False widens the list to every owned handset, including reserved and in-repair. */
  sellableOnly?: boolean;
  warehouseId?: number;
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
  /** POS screens open with the cursor already in the box. */
  autoFocus?: boolean;
  id?: string;
  search?: SearchFn<UnitOption>;
  className?: string;
}

const DEFAULT_ENDPOINT = '/inventory/units/search';

/** A complete IMEI. At this length the scanner has finished and there is one answer. */
const IMEI_LENGTH = 15;

/**
 * Choose one physical handset.
 *
 * Scan-first, deliberately: this is not a dropdown with a search box hidden inside it,
 * it IS a search box. A salesperson points a reader at the box, the code lands in the
 * field, and the device is selected without a click — anything that needs the mouse
 * first has already lost to the paper notebook.
 *
 * Three behaviours that come from that:
 *
 * - **A complete IMEI with exactly one match selects itself.** Fifteen digits is the
 *   scanner saying it is done, and asking someone to then press Enter on a
 *   single-item list is ceremony.
 * - **Digits are normalised before they are sent.** A phone typed with a Persian
 *   keypad and one read off a barcode must find the same handset.
 * - **Focus never leaves the field.** The list opens beneath it; the scanner's next
 *   read still lands in the box.
 */
export function UnitPicker({
  value,
  onChange,
  sellableOnly = true,
  warehouseId,
  // A sample IMEI, not a Persian sentence: the field is dir="ltr" so its contents read
  // like the sticker on the box, and Persian prose sitting in that direction context
  // reads as broken. The instruction belongs in a <Label/> above the field.
  placeholder = '356938035643809',
  disabled = false,
  invalid = false,
  autoFocus = false,
  id,
  search,
  className,
}: UnitPickerProps) {
  const [open, setOpen] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const searchFn = useMemo(
    () =>
      search ??
      endpointSearch<UnitOption>(DEFAULT_ENDPOINT, {
        sellable: sellableOnly,
        ...(warehouseId ? { warehouse_id: warehouseId } : {}),
      }),
    [search, sellableOnly, warehouseId]
  );

  const { term, setTerm, results, status, retry } = useRemoteSearch(searchFn, {
    enabled: open && !disabled,
  });

  // A finished scan with one answer needs no confirmation. Guarded on the digit
  // length so a partial type-in never picks a device out from under the operator.
  useEffect(() => {
    const digits = toLatinDigits(term).replace(/\D/g, '');
    const only = results[0];

    if (status === 'ready' && results.length === 1 && only && digits.length >= IMEI_LENGTH) {
      select(only);
    }
    // Intentionally keyed on the search outcome only. `select` is re-created every
    // render, and depending on it would re-fire the auto-select immediately after the
    // caller clears the value.
  }, [status, results, term]);

  useEffect(() => setHighlighted(0), [results]);

  function select(unit: UnitOption): void {
    onChange(unit);
    setTerm('');
    setOpen(false);
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'Escape') {
      setOpen(false);

      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      setOpen(true);
      setHighlighted((index) => {
        const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

        return Math.max(0, Math.min(results.length - 1, next));
      });

      return;
    }

    if (event.key === 'Enter') {
      // Enter is the POS submit key (design-system rule 7). It must not reach the
      // surrounding form while this list is showing a candidate.
      const unit = results[highlighted];

      if (open && unit) {
        event.preventDefault();
        select(unit);
      }
    }
  }

  if (value) {
    return (
      <div className={cn('flex items-start gap-2', className)}>
        <SelectedUnit unit={value} />

        {!disabled && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label="حذف دستگاه انتخاب‌شده"
            onClick={() => {
              onChange(null);
              // Straight back to scanning: the next handset is usually right behind.
              window.setTimeout(() => inputRef.current?.focus(), 0);
            }}
          >
            <XIcon className="size-4" />
          </Button>
        )}
      </div>
    );
  }

  return (
    <Popover open={open && !disabled} onOpenChange={setOpen}>
      <PopoverAnchor asChild>
        <div className={cn('relative', className)}>
          {/* dir="ltr" goes on the WRAPPER, not just the field: the icon is placed
              with a logical utility, and inside an RTL context `end-3` would resolve
              to the side the Latin digits start on and sit on top of them. */}
          <div dir="ltr" className="relative">
            <ScanLineIcon
              className="pointer-events-none absolute inset-y-0 end-3 my-auto size-4 text-muted-foreground"
              aria-hidden
            />
            <Input
              ref={inputRef}
              id={id}
              type="text"
              role="combobox"
              aria-expanded={open}
              aria-autocomplete="list"
              autoComplete="off"
              autoFocus={autoFocus}
              disabled={disabled}
              value={term}
              placeholder={placeholder}
              dir="ltr"
              // No `inputMode="numeric"`: this box also finds a device by model name,
              // and a forced numeric keypad would make that impossible on a phone.
              className={cn('tabular h-11 pe-9', invalid && 'border-destructive')}
              onChange={(event) => {
                setTerm(event.target.value);
                setOpen(true);
              }}
              onFocus={() => setOpen(true)}
              onKeyDown={onKeyDown}
            />
          </div>
        </div>
      </PopoverAnchor>

      <PopoverContent
        dir="rtl"
        align="start"
        className="max-h-80 w-(--radix-popover-trigger-width) min-w-72 overflow-y-auto p-1"
        // Focus stays in the box so the scanner's next read lands there.
        onOpenAutoFocus={(event) => event.preventDefault()}
      >
        {status === 'loading' && <PickerSkeleton />}

        {status === 'error' && (
          <div className="flex flex-col items-center gap-2 px-3 py-6 text-center">
            <AlertTriangleIcon className="size-5 text-destructive" aria-hidden />
            <p className="text-xs text-muted-foreground">
              فهرست دستگاه‌ها بارگذاری نشد. اتصال را بررسی کنید.
            </p>
            <Button type="button" variant="outline" onClick={retry}>
              تلاش دوباره
            </Button>
          </div>
        )}

        {status === 'ready' && results.length === 0 && (
          <p className="px-3 py-6 text-center text-xs text-muted-foreground">
            {term.trim()
              ? `دستگاهی با «${term.trim()}» موجود نیست. شاید فروخته شده یا در شعبه دیگری باشد.`
              : 'بارکد دستگاه را اسکن کنید یا IMEI را بنویسید.'}
          </p>
        )}

        {status === 'ready' && results.length > 0 && (
          <ul role="listbox" className="space-y-0.5">
            {results.map((unit, index) => (
              <li key={unit.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={index === highlighted}
                  onMouseEnter={() => setHighlighted(index)}
                  onClick={() => select(unit)}
                  className={cn(
                    'flex min-h-11 w-full flex-col gap-1 rounded-inner px-2 py-2 text-start',
                    index === highlighted && 'bg-muted'
                  )}
                >
                  <UnitSummary unit={unit} />
                </button>
              </li>
            ))}
          </ul>
        )}
      </PopoverContent>
    </Popover>
  );
}

function SelectedUnit({ unit }: { unit: UnitOption }) {
  return (
    <div className="min-w-0 flex-1 rounded-control border border-input px-3 py-2">
      <UnitSummary unit={unit} />
    </div>
  );
}

function UnitSummary({ unit }: { unit: UnitOption }) {
  const code = unit.imei1 ?? unit.serial;

  return (
    <>
      <span className="flex min-w-0 items-center gap-2">
        <SmartphoneIcon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="truncate text-sm font-medium">{unit.product_name}</span>
        <StatusBadge status={unit.status} className="ms-auto shrink-0" />
      </span>

      <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 ps-6 text-2xs text-muted-foreground">
        {code && <Num value={code} variant="ltr" />}
        <span aria-hidden>·</span>
        <span className="truncate">{unit.variant_name}</span>
        <span aria-hidden>·</span>
        <span>{unit.condition_label}</span>
        {unit.grade && <span>({unit.grade})</span>}
        {unit.warehouse_name && (
          <>
            <span aria-hidden>·</span>
            <span className="truncate">{unit.warehouse_name}</span>
          </>
        )}
        {unit.cost && (
          <>
            <span aria-hidden>·</span>
            <span>
              خرید <Money rial={unit.cost.value} digits="latin" />
            </span>
          </>
        )}
      </span>
    </>
  );
}
