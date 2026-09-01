import { AlertTriangleIcon, ChevronsUpDownIcon, UserRoundIcon, XIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Money } from '@/components/domain/money';
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
import type { MoneyValue } from '@/types';

export interface PartyOption {
  id: number;
  name: string;
  company_name: string | null;
  kind: string;
  kind_label: string;
  mobile: string | null;
  /** Signed rial; positive means they owe the shop. Null when staff may not see it. */
  balance: MoneyValue | null;
}

export interface PartyPickerProps {
  value: PartyOption | null;
  onChange: (party: PartyOption | null) => void;
  /** Bias the list — a purchase invoice opens on suppliers. Never a restriction. */
  kind?: 'customer' | 'supplier' | 'colleague' | 'both';
  placeholder?: string;
  disabled?: boolean;
  /** Draw the error ring; the message itself belongs under the field. */
  invalid?: boolean;
  id?: string;
  /** Override the data source. The gallery passes a fixture. */
  search?: SearchFn<PartyOption>;
  className?: string;
}

const DEFAULT_ENDPOINT = '/crm/parties/search';

/**
 * Choose a customer, supplier or همکار.
 *
 * Searching happens on the server, over name, company, national id and every stored
 * phone number at once — because the counter does not know which of those the person
 * in front of them will say, and a picker that only matches names sends staff back to
 * a paper notebook.
 *
 * Two decisions worth knowing:
 *
 * - **The balance rides along with the name.** Choosing a party is nearly always the
 *   moment someone needs to know what that party owes; making them open a second
 *   screen to find out is how a shop sells on credit to someone already over their
 *   limit. It is withheld entirely (not shown as zero) from staff without
 *   `crm.view_balance`.
 * - **`kind` biases, it does not restrict.** The same person sells you a trade-in and
 *   buys a charger in one visit (see `PartyKind`), so a supplier field still finds a
 *   customer — it just looks for suppliers first.
 */
export function PartyPicker({
  value,
  onChange,
  kind,
  placeholder = 'جستجوی نام، شرکت یا شماره تماس…',
  disabled = false,
  invalid = false,
  id,
  search,
  className,
}: PartyPickerProps) {
  const [open, setOpen] = useState(false);

  const searchFn = useMemo(
    () => search ?? endpointSearch<PartyOption>(DEFAULT_ENDPOINT, kind ? { kind } : {}),
    [search, kind]
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
            <UserRoundIcon className="size-4 shrink-0 text-muted-foreground" aria-hidden />

            {value ? (
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-baseline gap-x-2">
                  <span className="truncate font-medium">{value.name}</span>
                  {value.company_name && (
                    <span className="truncate text-2xs text-muted-foreground">
                      {value.company_name}
                    </span>
                  )}
                </span>
                <PartyMeta party={value} />
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

      {/* dir on the Content, per the RTL rule for Popover primitives. */}
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
                <p className="text-xs text-muted-foreground">
                  فهرست مشتریان بارگذاری نشد. اتصال را بررسی کنید.
                </p>
                <Button type="button" variant="outline" onClick={retry}>
                  تلاش دوباره
                </Button>
              </div>
            )}

            {status === 'ready' && results.length === 0 && (
              <CommandEmpty className="px-3 py-6 text-xs text-muted-foreground">
                {term.trim()
                  ? `طرف حسابی با «${term.trim()}» پیدا نشد.`
                  : 'برای جستجو، نام یا شماره تماس را بنویسید.'}
              </CommandEmpty>
            )}

            {status === 'ready' &&
              results.map((party) => (
                <CommandItem
                  key={party.id}
                  value={String(party.id)}
                  data-checked={value?.id === party.id}
                  onSelect={() => {
                    onChange(party);
                    setOpen(false);
                  }}
                  className="min-h-11 items-start gap-2 py-2"
                >
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-baseline gap-x-2">
                      <span className="truncate font-medium">{party.name}</span>
                      {party.company_name && (
                        <span className="truncate text-2xs text-muted-foreground">
                          {party.company_name}
                        </span>
                      )}
                    </span>
                    <PartyMeta party={party} />
                  </span>
                </CommandItem>
              ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}

/**
 * The second line: what kind of party, how to reach them, and what they owe.
 *
 * The balance is labelled بدهکار/بستانکار rather than shown with a minus sign. A
 * signed figure needs the reader to remember which direction is which; the two words
 * are what an Iranian bookkeeper already reads on a statement.
 */
function PartyMeta({ party }: { party: PartyOption }) {
  const balance = party.balance;

  return (
    <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-2xs text-muted-foreground">
      <span>{party.kind_label}</span>

      {party.mobile && (
        <>
          <span aria-hidden>·</span>
          <Num value={party.mobile} variant="ltr" />
        </>
      )}

      {balance && (
        <>
          <span aria-hidden>·</span>
          {balance.value === 0 ? (
            <span>تسویه</span>
          ) : (
            <span
              className={cn(
                'inline-flex items-center gap-1',
                balance.value > 0 ? 'text-warning' : 'text-muted-foreground'
              )}
            >
              {balance.value > 0 ? 'بدهکار' : 'بستانکار'}
              <Money rial={Math.abs(balance.value)} digits="latin" />
            </span>
          )}
        </>
      )}
    </span>
  );
}
