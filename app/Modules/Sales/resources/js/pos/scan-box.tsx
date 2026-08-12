import { AlertTriangleIcon, PackageIcon, ScanLineIcon, SmartphoneIcon } from 'lucide-react';
import { type KeyboardEvent, useEffect, useImperativeHandle, useRef, useState } from 'react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PickerSkeleton } from '@/components/domain/picker-skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';

import type { ScanCandidate } from './types';

export interface ScanBoxHandle {
  /** Put the cursor back in the box. The till returns here after every action. */
  focus: () => void;
}

interface ScanBoxProps {
  ref?: React.Ref<ScanBoxHandle>;
  /** Which customer is buying — changes the price level the server quotes. */
  partyId: number | null;
  branchId: number;
  /** Called with the chosen candidate. The page appends it to the basket. */
  onPick: (candidate: ScanCandidate) => void;
}

/** A complete IMEI. At this length the scanner has finished and there is one answer. */
const IMEI_LENGTH = 15;

/** One Persian word typed at speed. Matches `useRemoteSearch`. */
const DEBOUNCE_MS = 250;

/**
 * The box the whole till turns on.
 *
 * A salesperson points a reader at this field a hundred times a day. Everything else on
 * the screen can cost a click; this cannot cost anything.
 *
 * ## Four behaviours, each one earning its complexity
 *
 * 1. **Focus never leaves.** Not after a pick, not after an error, not after the payment
 *    box closes. The scanner's next read has to land somewhere, and if it lands on the
 *    body the code is typed into nothing and the operator does not notice until the
 *    third phone.
 * 2. **A finished scan with one answer adds itself.** Fifteen digits is the reader
 *    saying it is done; making somebody then press Enter on a one-item list is ceremony
 *    a hundred times a day. Guarded on the digit length so a half-typed number never
 *    picks something out from under them.
 * 3. **Enter takes the highlighted row.** The typed-a-name case, where several things
 *    match. Arrow keys move; Enter commits; nothing needs the mouse.
 * 4. **A blocked device is shown, not hidden.** Scanning a phone that was sold yesterday
 *    returns it with the reason attached. "Not found" would send the operator to the
 *    shelf to look for it.
 *
 * ## Why this does not reuse `<UnitPicker/>`
 *
 * `UnitPicker` finds a handset. This finds *whatever was scanned* — a handset by IMEI, a
 * charger by barcode, or a product by name — because the person holding the reader does
 * not know which of those they are pointing at, and should not have to.
 */
export function ScanBox({ ref, partyId, branchId, onPick }: ScanBoxProps) {
  const [term, setTerm] = useState('');
  const [candidates, setCandidates] = useState<ScanCandidate[]>([]);
  const [status, setStatus] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle');
  const [highlighted, setHighlighted] = useState(0);

  const inputRef = useRef<HTMLInputElement>(null);
  const sequence = useRef(0);

  useImperativeHandle(ref, () => ({
    focus: () => inputRef.current?.focus(),
  }));

  useEffect(() => {
    const trimmed = term.trim();

    if (trimmed === '') {
      setCandidates([]);
      setStatus('idle');

      return;
    }

    const controller = new AbortController();
    // Stale-response protection: type "sam" then "samsung", and the slower first query
    // must not overwrite the correct rows. Same guard as `useRemoteSearch`.
    const ticket = ++sequence.current;

    setStatus('loading');

    const timer = window.setTimeout(() => {
      const query = new URLSearchParams({ code: trimmed, branch_id: String(branchId) });

      if (partyId !== null) {
        query.set('party_id', String(partyId));
      }

      fetch(`/sales/pos/scan?${query.toString()}`, {
        signal: controller.signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then((response) => {
          if (!response.ok) throw new Error(`Scan failed with ${response.status}.`);

          return response.json() as Promise<{ results?: ScanCandidate[] }>;
        })
        .then((payload) => {
          if (ticket !== sequence.current) return;
          setCandidates(payload.results ?? []);
          setStatus('ready');
        })
        .catch((error: unknown) => {
          if (controller.signal.aborted || ticket !== sequence.current) return;
          setStatus('error');
          if (import.meta.env.DEV) console.error(error);
        });
    }, DEBOUNCE_MS);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [term, branchId, partyId]);

  useEffect(() => setHighlighted(0), [candidates]);

  // The finished-scan case. Only for a sellable single match: a blocked device has to be
  // read, not silently swallowed into a basket it cannot join.
  useEffect(() => {
    const digits = toLatinDigits(term).replace(/\D/g, '');
    const only = candidates[0];

    if (
      status === 'ready' &&
      candidates.length === 1 &&
      only &&
      only.sellable &&
      digits.length >= IMEI_LENGTH
    ) {
      pick(only);
    }
    // Keyed on the search outcome only — `pick` is re-created every render and depending
    // on it would re-fire the moment the caller clears the box.
  }, [status, candidates, term]);

  function pick(candidate: ScanCandidate): void {
    if (!candidate.sellable) {
      return;
    }

    onPick(candidate);
    setTerm('');
    setCandidates([]);
    setStatus('idle');
    inputRef.current?.focus();
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'Escape') {
      setTerm('');
      setCandidates([]);
      setStatus('idle');

      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      setHighlighted((index) => {
        const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

        return Math.max(0, Math.min(candidates.length - 1, next));
      });

      return;
    }

    if (event.key === 'Enter') {
      // Enter is the POS submit key (design-system rule 7). While this list is showing a
      // candidate, it belongs to the list — it must not reach the sale form behind it and
      // finalise a half-built basket.
      event.preventDefault();

      const candidate = candidates[highlighted];

      if (candidate) {
        pick(candidate);
      }
    }
  }

  return (
    <div className="space-y-2">
      <Label htmlFor="pos-scan">بارکد یا IMEI را اسکن کنید — یا نام کالا را بنویسید</Label>

      {/* dir="ltr" on the WRAPPER, not just the field: the icon is placed with a logical
          utility, and in an RTL context `end-3` resolves to the side the Latin digits
          start on and would sit on top of them. */}
      <div className="relative" dir="ltr">
        <ScanLineIcon
          className="pointer-events-none absolute inset-y-0 end-3 my-auto size-5 text-muted-foreground"
          aria-hidden
        />
        <Input
          ref={inputRef}
          id="pos-scan"
          type="text"
          role="combobox"
          aria-expanded={candidates.length > 0}
          aria-autocomplete="list"
          aria-controls="pos-scan-results"
          autoComplete="off"
          autoFocus
          dir="ltr"
          value={term}
          placeholder="356938035643809"
          className="tabular h-14 pe-10 text-lg"
          onChange={(event) => setTerm(event.target.value)}
          onKeyDown={onKeyDown}
        />
      </div>

      {status === 'loading' && (
        <div className="rounded-control border border-border p-1">
          <PickerSkeleton />
        </div>
      )}

      {status === 'error' && (
        <p className="flex items-center gap-2 rounded-control border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
          <AlertTriangleIcon className="size-4 shrink-0" aria-hidden />
          جستجو انجام نشد. اتصال را بررسی کنید و دوباره اسکن کنید.
        </p>
      )}

      {status === 'ready' && candidates.length === 0 && (
        <p className="rounded-control border border-border px-3 py-3 text-sm text-muted-foreground">
          چیزی با «{term.trim()}» پیدا نشد. شاید در شعبه دیگری باشد یا هنوز ثبت نشده.
        </p>
      )}

      {status === 'ready' && candidates.length > 0 && (
        <ul
          id="pos-scan-results"
          role="listbox"
          className="max-h-72 space-y-0.5 overflow-y-auto rounded-control border border-border p-1"
        >
          {candidates.map((candidate, index) => (
            <li key={candidate.key}>
              <button
                type="button"
                role="option"
                aria-selected={index === highlighted}
                disabled={!candidate.sellable}
                onMouseEnter={() => setHighlighted(index)}
                onClick={() => pick(candidate)}
                className={cn(
                  'flex min-h-11 w-full flex-col gap-1 rounded-inner px-2 py-2 text-start',
                  index === highlighted && candidate.sellable && 'bg-muted',
                  !candidate.sellable && 'opacity-70'
                )}
              >
                <CandidateSummary candidate={candidate} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function CandidateSummary({ candidate }: { candidate: ScanCandidate }) {
  const Icon = candidate.kind === 'unit' ? SmartphoneIcon : PackageIcon;

  return (
    <>
      <span className="flex min-w-0 items-center gap-2">
        <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="truncate text-sm font-medium">{candidate.product_name}</span>
        <span className="ms-auto shrink-0 text-sm font-medium">
          <Money rial={candidate.unit_price.value} digits="latin" />
        </span>
      </span>

      <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 ps-6 text-2xs text-muted-foreground">
        <span className="truncate">{candidate.variant_name}</span>

        {candidate.imei && (
          <>
            <span aria-hidden>·</span>
            <Num value={candidate.imei} variant="ltr" />
          </>
        )}

        {candidate.condition_label && (
          <>
            <span aria-hidden>·</span>
            <span>{candidate.condition_label}</span>
          </>
        )}

        {candidate.on_hand !== null && (
          <>
            <span aria-hidden>·</span>
            {/* Zero is stated in words rather than shown as a bare ۰, which reads as a
                price at a glance in a row that already carries three numbers. */}
            <span className={cn(candidate.on_hand <= 0 && 'text-warning')}>
              {candidate.on_hand <= 0 ? (
                'موجود نیست'
              ) : (
                <>
                  موجودی <Num value={candidate.on_hand} variant="prose" />
                </>
              )}
            </span>
          </>
        )}
      </span>

      {candidate.blocked_reason && (
        <span className="flex items-center gap-1 ps-6 text-2xs text-destructive">
          <AlertTriangleIcon className="size-3 shrink-0" aria-hidden />
          {candidate.blocked_reason}
        </span>
      )}
    </>
  );
}
