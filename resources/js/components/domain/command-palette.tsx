import { router } from '@inertiajs/react';
import { SearchIcon, SmartphoneIcon, UsersIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import {
  Command,
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command';
import { endpointSearch, useRemoteSearch } from '@/hooks/use-remote-search';
import { NAVIGATION } from '@/lib/navigation';
import type { Features } from '@/types';

interface PartyHit {
  id: number;
  name: string;
  company_name: string | null;
  kind_label: string;
  mobile: string | null;
}

interface UnitHit {
  id: number;
  imei1: string | null;
  product_name: string;
  variant_label: string | null;
  status_label: string | null;
}

/**
 * The header search, which for the whole life of this application did nothing.
 *
 * `app-shell.tsx` rendered a `<Button variant="outline">` carrying a magnifier and the
 * placeholder «جستجوی کالا، مشتری، IMEI یا شماره قبض…» — with no `onClick`, no dialog, no
 * keyboard shortcut and no endpoint behind it. The most prominent control in the product,
 * on every screen, was decoration. A control that looks interactive and is not is worse
 * than no control: it teaches people the software is broken rather than that the feature
 * is missing.
 *
 * ## What it searches, and what the placeholder now says
 *
 * Customers and handsets, through the endpoints the pickers already use — both policy
 * authorised, tenant scoped and throttled, so nothing new is exposed. Plus every screen in
 * the navigation, because "take me to انبارگردانی" is the other half of what a palette is
 * for and it costs no request at all.
 *
 * It does **not** search products or invoice numbers, which the old placeholder promised.
 * The only general product search in the app renders a barcode image per row for the label
 * sheet, which is not a thing to do on every keystroke, and nothing indexes invoice
 * numbers. So the placeholder was narrowed to what this actually does. Promising two
 * things it cannot do is how the previous version got away with doing nothing.
 *
 * A unified endpoint covering both is the obvious next step; it is a backend change with
 * its own authorisation questions, and it is not this.
 *
 * ## Navigation is filtered the way the sidebar is
 *
 * Same `features` map, same reasoning: a module switched off should not be offered here
 * either. Convenience, never authorisation — the route stays guarded.
 */
export function CommandPalette({ features }: { features: Features }) {
  const [open, setOpen] = useState(false);

  // ⌘K on a Mac, Ctrl+K everywhere else. `event.key` is layout-dependent, but so is every
  // shortcut a user has learned, and this is the one every palette in every tool uses.
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOpen((value) => !value);
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  const destinations = useMemo(
    () =>
      NAVIGATION.flatMap((section) =>
        section.items
          .filter((item) => item.feature === undefined || features[item.feature] !== false)
          .map((item) => ({ ...item, section: section.label }))
      ),
    [features]
  );

  const partySearch = useMemo(() => endpointSearch<PartyHit>('/crm/parties/search'), []);
  // `sellable=0` so the passport of a sold, reserved or in-repair handset is findable.
  // The picker default is the opposite, because the till may only offer what it can sell.
  const unitSearch = useMemo(
    () => endpointSearch<UnitHit>('/inventory/units/search', { sellable: 0 }),
    []
  );

  const [term, setLocalTerm] = useState('');

  /*
    Two characters before either endpoint is asked anything.

    `enabled: open` alone fired both searches the moment the palette opened, on an empty
    term — so simply pressing ⌘K cost two requests and filled the list with an arbitrary
    handful of customers and handsets nobody had asked for. Measured: 31 rows on an empty
    box, of which 19 were the navigation somebody actually wanted to see.
  */
  const remote = open && term.trim().length >= 2;

  const parties = useRemoteSearch(partySearch, { enabled: remote });
  const units = useRemoteSearch(unitSearch, { enabled: remote });

  const setTerm = useCallback(
    (value: string) => {
      setLocalTerm(value);
      parties.setTerm(value);
      units.setTerm(value);
    },
    [parties, units]
  );

  const go = useCallback((href: string) => {
    setOpen(false);
    router.visit(href);
  }, []);

  const searching = remote && (parties.status === 'loading' || units.status === 'loading');
  const hasRemote = parties.results.length > 0 || units.results.length > 0;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="flex h-10 min-w-0 max-w-md shrink flex-1 items-center gap-2 rounded-pill border border-input px-3.5 text-start text-muted-foreground transition-colors hover:bg-accent focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      >
        <SearchIcon className="size-4 shrink-0" aria-hidden />
        <span className="truncate text-sm">جستجوی مشتری، IMEI یا رفتن به بخش‌ها…</span>
        {/* The shortcut is shown, not hidden: a palette nobody knows the shortcut for is a
            palette everybody reaches for with the mouse. Hidden below `sm`, where there is
            no keyboard to press it with. */}
        <kbd className="ltr-value ms-auto hidden shrink-0 rounded-inner border border-border px-1.5 py-0.5 text-2xs sm:inline-block">
          ⌘K
        </kbd>
      </button>

      <CommandDialog
        open={open}
        onOpenChange={setOpen}
        title="جستجو"
        description="جستجوی مشتری، دستگاه یا رفتن به بخش‌های برنامه"
      >
        {/* `shouldFilter={false}`: cmdk's own fuzzy filter would re-filter rows the server
            already matched, and it scores Persian poorly — a customer the endpoint found
            would vanish from the list that is supposed to show it. Navigation is filtered
            here instead, on the same term. */}
        <Command shouldFilter={false} dir="rtl">
          <CommandInput
            value={term}
            onValueChange={setTerm}
            placeholder="نام مشتری، شمارهٔ موبایل، IMEI یا نام یک بخش…"
          />

          <CommandList>
            {term !== '' && !searching && !hasRemote && <CommandEmpty>چیزی پیدا نشد.</CommandEmpty>}

            <NavigationGroup destinations={destinations} term={term} onSelect={go} />

            {parties.results.length > 0 && (
              <>
                <CommandSeparator />
                <CommandGroup heading="مشتریان">
                  {parties.results.map((party) => (
                    <CommandItem
                      key={`party-${party.id}`}
                      value={`party-${party.id}`}
                      onSelect={() => go(`/crm/parties/${party.id}`)}
                    >
                      <UsersIcon aria-hidden />
                      <span className="min-w-0">
                        <span className="block truncate">{party.name}</span>
                        <span className="block truncate text-2xs text-muted-foreground">
                          {party.kind_label}
                          {party.mobile && (
                            <>
                              {' · '}
                              <span className="ltr-value">{party.mobile}</span>
                            </>
                          )}
                        </span>
                      </span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </>
            )}

            {units.results.length > 0 && (
              <>
                <CommandSeparator />
                <CommandGroup heading="دستگاه‌ها">
                  {units.results.map((unit) => (
                    <CommandItem
                      key={`unit-${unit.id}`}
                      value={`unit-${unit.id}`}
                      onSelect={() => go(`/inventory/units/${unit.id}`)}
                    >
                      <SmartphoneIcon aria-hidden />
                      <span className="min-w-0">
                        <span className="block truncate">{unit.product_name}</span>
                        <span className="block truncate text-2xs text-muted-foreground">
                          {unit.imei1 && <span className="ltr-value">{unit.imei1}</span>}
                          {unit.status_label && ` · ${unit.status_label}`}
                        </span>
                      </span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </CommandDialog>
    </>
  );
}

/**
 * Every screen, matched on its own label.
 *
 * Shown unfiltered when the box is empty, which makes the palette a map of the product
 * rather than a thing you have to already know what to type into.
 */
function NavigationGroup({
  destinations,
  term,
  onSelect,
}: {
  destinations: { label: string; href: string; section: string; icon: React.ElementType }[];
  term: string;
  onSelect: (href: string) => void;
}) {
  const matches = useMemo(() => {
    const needle = term.trim();

    if (needle === '') {
      return destinations;
    }

    return destinations.filter(
      (item) => item.label.includes(needle) || item.section.includes(needle)
    );
  }, [destinations, term]);

  if (matches.length === 0) {
    return null;
  }

  return (
    <CommandGroup heading="رفتن به">
      {matches.map((item) => (
        <CommandItem
          key={item.href}
          value={`nav-${item.href}`}
          onSelect={() => onSelect(item.href)}
        >
          <item.icon aria-hidden />
          <span className="truncate">{item.label}</span>
          <span className="ms-auto shrink-0 text-2xs text-muted-foreground">{item.section}</span>
        </CommandItem>
      ))}
    </CommandGroup>
  );
}
