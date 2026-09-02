import { AlertTriangleIcon, ChevronsUpDownIcon, PackageIcon, XIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Num } from '@/components/domain/num';
import { PickerSkeleton } from '@/components/domain/picker-skeleton';
import { Button } from '@/components/ui/button';
import {
  Command,
  CommandEmpty,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { type SearchFn, endpointSearch, useRemoteSearch } from '@/hooks/use-remote-search';
import { cn } from '@/lib/utils';

export interface VariantOption {
  id: number;
  product_name: string;
  variant_name: string;
  barcode: string | null;
}

export interface VariantPickerProps {
  value: VariantOption | null;
  onChange: (variant: VariantOption | null) => void;
  /**
   * Which side of the catalogue to offer. The two are not interchangeable: a purchase
   * line for a phone needs IMEIs and a line for a charger needs a quantity, so a picker
   * that mixed them would offer a choice the form beneath it cannot honour.
   */
  serialized?: boolean;
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
  id?: string;
  /** Override the data source. The gallery passes a fixture. */
  search?: SearchFn<VariantOption>;
  className?: string;
}

const DEFAULT_ENDPOINT = '/purchasing/variants';

/**
 * Choose a catalogue line — a product variant, which is what stock, prices and
 * purchase lines all actually point at.
 *
 * Searches on the server over product name, barcode and SKU. Deliberately shows the
 * variant name under the product: "iPhone 15 Pro" is four different things once colour
 * and storage are settled, and picking the wrong one is invisible until the stock
 * figure is wrong.
 */
export function VariantPicker({
  value,
  onChange,
  serialized = false,
  placeholder = 'جستجوی نام کالا یا بارکد…',
  disabled = false,
  invalid = false,
  id,
  search,
  className,
}: VariantPickerProps) {
  const [open, setOpen] = useState(false);

  const searchFn = useMemo(
    () => search ?? endpointSearch<VariantOption>(DEFAULT_ENDPOINT, { serialized }),
    [search, serialized]
  );

  const { term, setTerm, results, status, retry } = useRemoteSearch(searchFn, {
    enabled: open && !disabled,
  });

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <div className={cn('flex items-center gap-1', className)}>
        <PopoverTrigger asChild>
          <button
            type="button"
            id={id}
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              'flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-control border bg-transparent px-3 py-2 text-start text-sm transition-colors',
              'hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50',
              invalid ? 'border-destructive' : 'border-input'
            )}
          >
            <PackageIcon className="size-4 shrink-0 text-muted-foreground" aria-hidden />

            {value ? (
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{value.product_name}</span>
                <span className="block truncate text-2xs text-muted-foreground">
                  {value.variant_name}
                </span>
              </span>
            ) : (
              <span className="min-w-0 flex-1 truncate text-muted-foreground">{placeholder}</span>
            )}

            <ChevronsUpDownIcon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          </button>
        </PopoverTrigger>

        {value && !disabled && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label="حذف انتخاب"
            onClick={() => onChange(null)}
          >
            <XIcon className="size-4" />
          </Button>
        )}
      </div>

      <PopoverContent
        dir="rtl"
        align="start"
        className="w-(--radix-popover-trigger-width) min-w-72 p-0"
      >
        <Command shouldFilter={false}>
          <CommandInput value={term} onValueChange={setTerm} placeholder={placeholder} />

          <CommandList>
            {status === 'loading' && <PickerSkeleton />}

            {status === 'error' && (
              <div className="flex flex-col items-center gap-2 px-3 py-6 text-center">
                <AlertTriangleIcon className="size-5 text-destructive" aria-hidden />
                <p className="text-xs text-muted-foreground">فهرست کالاها بارگذاری نشد.</p>
                <Button type="button" variant="outline" onClick={retry}>
                  تلاش دوباره
                </Button>
              </div>
            )}

            {status === 'ready' && results.length === 0 && (
              <CommandEmpty className="px-3 py-6 text-xs text-muted-foreground">
                {term.trim()
                  ? `کالایی با «${term.trim()}» پیدا نشد.`
                  : serialized
                    ? 'کالای سریال‌دار فعالی ثبت نشده است.'
                    : 'کالای عادی فعالی ثبت نشده است.'}
              </CommandEmpty>
            )}

            {status === 'ready' &&
              results.map((variant) => (
                <CommandItem
                  key={variant.id}
                  value={String(variant.id)}
                  data-checked={value?.id === variant.id}
                  onSelect={() => {
                    onChange(variant);
                    setOpen(false);
                  }}
                  className="min-h-11 items-start gap-2 py-2"
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{variant.product_name}</span>
                    <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                      <span className="truncate">{variant.variant_name}</span>
                      {variant.barcode && (
                        <>
                          <span aria-hidden>·</span>
                          <Num value={variant.barcode} variant="ltr" />
                        </>
                      )}
                    </span>
                  </span>
                </CommandItem>
              ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
