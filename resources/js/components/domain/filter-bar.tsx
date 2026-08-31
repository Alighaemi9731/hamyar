import { FilterIcon, SearchIcon, XIcon } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

export interface FilterOption {
  value: string;
  label: string;
}

export interface FilterGroup {
  /** The query-string key this group writes. */
  key: string;
  /** «وضعیت», «نوع» — shown as the group's heading in the sheet. */
  label: string;
  /** The value currently applied, or `null` for "no filter". */
  value: string | null;
  options: FilterOption[];
  /** The "no filter" chip. Defaults to «همه». */
  allLabel?: string;
}

export interface FilterBarProps {
  search?: {
    value: string;
    placeholder: string;
    /** The accessible name. A placeholder is not a label. */
    label: string;
    /** The query-string key. Defaults to `q`. */
    key?: string;
  };
  groups?: FilterGroup[];
  /** Called with the keys that changed. The page owns the visit. */
  onChange: (changes: Record<string, string | null>) => void;
  /** How many rows the current filter produced. Announced, not just drawn. */
  resultCount?: number;
  /** «فاکتور», «مشتری» — the noun in that announcement. */
  resultUnit?: string;
  /** Anything this bar cannot express: a date range, a branch picker. */
  children?: ReactNode;
  className?: string;
}

/** The debounce every list page had written for itself. */
const SEARCH_DELAY = 300;

/**
 * Merge a filter change into the current filters and drop what is empty.
 *
 * `FilterBar` reports a cleared filter as `null`, and Inertia serialises `null` as an
 * empty parameter — so resetting a filtered list produced `/sales?q=&status=` rather than
 * `/sales`. Functionally identical, and a URL a shop copies out of the address bar and
 * sends to somebody. Every page that adopts `FilterBar` would otherwise write this same
 * three-line strip for itself, which is the duplication the component exists to end.
 */
export function withoutEmpty(
  filters: Record<string, string | number | boolean | null | undefined>
): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {};

  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '' || value === false) {
      continue;
    }

    out[key] = value;
  }

  return out;
}

/**
 * The filter row, once.
 *
 * ## What it replaces
 *
 * Twelve list pages each grew their own: a `useState` for the term, a `useEffect` with a
 * 300ms timer, a `visit()` that spreads the current filters and merges a change, and a row
 * of `<Button variant={active ? 'default' : 'outline'}>` chips. Same four things, twelve
 * times, and they had already drifted — some debounce at 300ms and some at 250, some
 * preserve scroll and some do not.
 *
 * ## The chips are 40px
 *
 * They were `size="sm"` — 28px. The design system's rule 9 sets the floor at 40 and
 * `button.tsx` used to carve out chips as a case that could ask for less; the two
 * contradicted each other, and the contradiction was settled in favour of the floor
 * (2026-08-31). A status filter is often the only way to narrow a list, which makes it a
 * primary control on a phone, not a decoration.
 *
 * ## Below `md` the filters move into a sheet
 *
 * A row of chips that wraps to three lines on a 375px screen pushes the table it is meant
 * to filter below the fold. The trigger carries a count, so "this list is filtered" is
 * legible without opening anything — the state a collapsed filter bar most easily hides.
 *
 * ## The result count is announced
 *
 * `aria-live="polite"` on the count. Filtering a list is the archetypal change a sighted
 * user sees instantly and a screen-reader user is told nothing about: the rows swap under
 * a control that has not moved.
 */
export function FilterBar({
  search,
  groups = [],
  onChange,
  resultCount,
  resultUnit,
  children,
  className,
}: FilterBarProps) {
  const searchKey = search?.key ?? 'q';

  const [term, setTerm] = useState(search?.value ?? '');
  const first = useRef(true);

  // Debounced, and deliberately not on the first render: mounting a filtered page would
  // otherwise fire a redundant visit for the term already in the URL.
  useEffect(() => {
    if (first.current) {
      first.current = false;

      return;
    }

    const timer = window.setTimeout(() => onChange({ [searchKey]: term || null }), SEARCH_DELAY);

    return () => window.clearTimeout(timer);
    // `term` only. Including `onChange` would restart the timer on every render of the
    // parent, which is every keystroke — the debounce would never fire.
  }, [term]);

  const activeCount = groups.filter((group) => group.value !== null).length + (term ? 1 : 0);

  function reset() {
    setTerm('');

    const cleared: Record<string, string | null> = { [searchKey]: null };

    for (const group of groups) {
      cleared[group.key] = null;
    }

    onChange(cleared);
  }

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex flex-wrap items-center gap-2">
        {search && (
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <SearchIcon
              className="pointer-events-none absolute inset-block-0 start-3 my-auto size-4 text-muted-foreground"
              aria-hidden
            />
            <Input
              aria-label={search.label}
              className="ps-9"
              placeholder={search.placeholder}
              value={term}
              onChange={(event) => setTerm(event.target.value)}
            />
          </div>
        )}

        {/* Inline from `md`. Below that the same groups live in the sheet. */}
        {groups.length > 0 && (
          <div className="hidden flex-wrap items-center gap-1.5 md:flex">
            {groups.map((group) => (
              <ChipGroup key={group.key} group={group} onChange={onChange} />
            ))}
          </div>
        )}

        {groups.length > 0 && (
          <Sheet>
            <SheetTrigger asChild>
              <Button variant="outline" className="md:hidden">
                <FilterIcon aria-hidden />
                فیلترها
                {activeCount > 0 && (
                  <span className="flex size-5 items-center justify-center rounded-full bg-primary text-2xs text-primary-foreground">
                    <Num value={activeCount} />
                  </span>
                )}
              </Button>
            </SheetTrigger>

            {/* In RTL `side="right"` is the reading-start edge — the same side the desktop
                sidebar occupies. */}
            <SheetContent side="right" dir="rtl" className="w-80">
              <SheetHeader>
                <SheetTitle>فیلترها</SheetTitle>
                <SheetDescription>فهرست را باریک کنید.</SheetDescription>
              </SheetHeader>

              <div className="space-y-6 overflow-y-auto px-4 pb-4">
                {groups.map((group) => (
                  <div key={group.key}>
                    <p className="mb-2 text-sm font-medium">{group.label}</p>
                    <ChipGroup group={group} onChange={onChange} />
                  </div>
                ))}
              </div>
            </SheetContent>
          </Sheet>
        )}

        {children}

        {activeCount > 0 && (
          <Button variant="ghost" onClick={reset}>
            <XIcon aria-hidden />
            پاک کردن
          </Button>
        )}
      </div>

      {resultCount !== undefined && (
        // Polite, not assertive: the count changes on every keystroke of a debounced
        // search, and an assertive region would interrupt the typing that caused it.
        <p aria-live="polite" className="text-xs text-muted-foreground">
          <Num value={resultCount} /> {resultUnit ?? 'ردیف'}
          {activeCount > 0 && ' با این فیلتر'}
        </p>
      )}
    </div>
  );
}

function ChipGroup({
  group,
  onChange,
}: {
  group: FilterGroup;
  onChange: (changes: Record<string, string | null>) => void;
}) {
  return (
    <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={group.label}>
      <Chip
        active={group.value === null}
        onClick={() => onChange({ [group.key]: null })}
        label={group.allLabel ?? 'همه'}
      />

      {group.options.map((option) => (
        <Chip
          key={option.value}
          active={group.value === option.value}
          onClick={() => onChange({ [group.key]: option.value })}
          label={option.label}
        />
      ))}
    </div>
  );
}

function Chip({ active, onClick, label }: { active: boolean; onClick: () => void; label: string }) {
  return (
    <Button
      type="button"
      variant={active ? 'default' : 'outline'}
      onClick={onClick}
      // `aria-pressed` rather than relying on the fill: a toggle that communicates its
      // state only through colour communicates nothing to a screen reader, and nothing to
      // a shopkeeper who cannot tell the brand blue from the outline in bright sun.
      aria-pressed={active}
    >
      {label}
    </Button>
  );
}
