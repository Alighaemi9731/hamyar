import { router, useForm } from '@inertiajs/react';
import { CheckIcon, LoaderCircleIcon, PlusIcon, XIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { MoneyField } from '@/components/domain/money-field';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MoneyValue } from '@/types';

interface Part {
  id: number;
  name: string;
  variant_name: string | null;
  quantity: number;
  state: string;
  unit_price: MoneyValue;
}

interface Candidate {
  variant_id: number;
  product_name: string;
  variant_name: string | null;
  available: number;
}

interface PartsPanelProps {
  ticketId: number;
  parts: Part[];
  /** False once the ticket is closed — the panel then reads as a record, not a tool. */
  editable: boolean;
  /** Errors that belong to the panel rather than to one of its fields. */
  error?: string;
}

const STATE_LABEL: Record<string, string> = {
  reserved: 'رزرو شده',
  consumed: 'مصرف شده',
  returned: 'برگشت داده شده',
};

/**
 * Parts on a job.
 *
 * ## Reserved and consumed are shown differently on purpose
 *
 * They are two different physical claims. A reserved part is in the drawer with the
 * shop's name on it and can still be given back; a consumed part is inside the customer's
 * device and has already left the stock ledger. Drawing them as one list of "parts" is
 * how a technician ends up releasing something that was fitted an hour ago.
 *
 * ## Fitting is its own press
 *
 * Consuming on the transition to «آماده» would save a click and would be wrong on exactly
 * the jobs that matter: a bench often plans two possible fixes and fits one. The
 * unfitted hold has to be released, not silently consumed, or a screen leaves the ledger
 * while still sitting in the drawer.
 *
 * ## The search is debounced, and the last response wins
 *
 * A technician types faster than a round trip. Without the guard the results flicker
 * between what «گلس» matched and what «گ» matched, and a part gets picked from a list
 * that has already been replaced under the cursor.
 */
export function PartsPanel({ ticketId, parts, editable, error }: PartsPanelProps) {
  const [term, setTerm] = useState('');
  const [results, setResults] = useState<Candidate[]>([]);
  const [searching, setSearching] = useState(false);
  const [picked, setPicked] = useState<Candidate | null>(null);
  const request = useRef(0);
  const toman = useTenantSettings().currency_display === 'toman';

  const form = useForm<{ variant_id: number | null; quantity: number; unit_price: number }>({
    variant_id: null,
    quantity: 1,
    unit_price: 0,
  });

  useEffect(() => {
    if (term.trim().length < 2) {
      setResults([]);

      return;
    }

    const id = ++request.current;
    const timer = window.setTimeout(() => {
      setSearching(true);

      fetch(`/repairs/tickets/${ticketId}/parts/search?q=${encodeURIComponent(term)}`, {
        headers: { Accept: 'application/json' },
      })
        .then((response) => response.json())
        .then((payload: { results: Candidate[] }) => {
          // A slower earlier request must not overwrite a newer answer.
          if (id === request.current) setResults(payload.results ?? []);
        })
        .catch(() => setResults([]))
        .finally(() => {
          if (id === request.current) setSearching(false);
        });
    }, 250);

    return () => window.clearTimeout(timer);
  }, [term, ticketId]);

  const reserved = parts.filter((part) => part.state === 'reserved');
  const consumed = parts.filter((part) => part.state === 'consumed');

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold">قطعات</h2>

      {error && (
        <p
          role="alert"
          className="rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {error}
        </p>
      )}

      {parts.length === 0 && !editable && (
        <p className="text-sm text-muted-foreground">قطعه‌ای برای این تعمیر ثبت نشده است.</p>
      )}

      {parts.length > 0 && (
        <ul className="space-y-2">
          {[...reserved, ...consumed].map((part) => (
            <li
              key={part.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-control border border-border px-3 py-2 text-sm"
            >
              <span className="min-w-0">
                {part.name}
                {part.variant_name && (
                  <span className="ms-1 text-2xs text-muted-foreground">{part.variant_name}</span>
                )}
                <span className="ms-2 text-2xs text-muted-foreground">
                  {STATE_LABEL[part.state] ?? part.state} · {part.quantity} عدد
                </span>
              </span>

              <span className="flex items-center gap-2">
                <Money rial={part.unit_price.value * part.quantity} digits="latin" />

                {editable && part.state === 'reserved' && (
                  <>
                    <Button
                      type="button"

                      variant="secondary"
                      onClick={() =>
                        router.post(
                          `/repairs/tickets/${ticketId}/parts/${part.id}/consume`,
                          {},
                          { preserveScroll: true }
                        )
                      }
                    >
                      <CheckIcon className="size-4" aria-hidden />
                      مصرف شد
                    </Button>

                    <Button
                      type="button"

                      variant="ghost"
                      aria-label={`آزاد کردن ${part.name}`}
                      onClick={() =>
                        router.delete(`/repairs/tickets/${ticketId}/parts/${part.id}`, {
                          preserveScroll: true,
                        })
                      }
                    >
                      <XIcon className="size-4" aria-hidden />
                    </Button>
                  </>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}

      {editable && (
        <div className="space-y-3 rounded-card border border-dashed border-border p-3">
          <div className="space-y-1">
            <Label htmlFor="part-search">افزودن قطعه</Label>
            <Input
              id="part-search"
              value={term}
              autoComplete="off"
              placeholder="نام قطعه یا بارکد"
              onChange={(event) => {
                setTerm(event.target.value);
                setPicked(null);
                form.setData('variant_id', null);
              }}
            />
          </div>

          {searching && (
            <p className="flex items-center gap-2 text-2xs text-muted-foreground">
              <LoaderCircleIcon className="size-3 animate-spin" aria-hidden />
              در حال جست‌وجو…
            </p>
          )}

          {!picked && results.length > 0 && (
            <ul className="max-h-56 overflow-y-auto rounded-control border border-border">
              {results.map((candidate) => (
                <li key={candidate.variant_id}>
                  <button
                    type="button"
                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-muted"
                    onClick={() => {
                      setPicked(candidate);
                      form.setData('variant_id', candidate.variant_id);
                    }}
                  >
                    <span className="min-w-0">
                      {candidate.product_name}
                      {candidate.variant_name && (
                        <span className="ms-1 text-2xs text-muted-foreground">
                          {candidate.variant_name}
                        </span>
                      )}
                    </span>
                    {/*
                      Available, not on hand — net of what other jobs are already
                      holding. It is the only number that answers "may I plan this in".
                    */}
                    <span className="tabular shrink-0 text-2xs text-muted-foreground">
                      {candidate.available} قابل تخصیص
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {picked && (
            <form
              className="space-y-3"
              onSubmit={(event) => {
                event.preventDefault();
                form.post(`/repairs/tickets/${ticketId}/parts`, {
                  preserveScroll: true,
                  onSuccess: () => {
                    form.reset();
                    setPicked(null);
                    setTerm('');
                    setResults([]);
                  },
                });
              }}
            >
              <p className="text-sm font-medium">
                {picked.product_name}
                <span className="ms-2 text-2xs text-muted-foreground">
                  {picked.available} قابل تخصیص
                </span>
              </p>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label htmlFor="part-quantity">تعداد</Label>
                  <Input
                    id="part-quantity"
                    type="number"
                    min={1}
                    value={form.data.quantity}
                    onChange={(event) => form.setData('quantity', Number(event.target.value))}
                  />
                </div>

                <div className="space-y-1">
                  <Label htmlFor="part-price">قیمت فروش</Label>
                  <MoneyField
                    id="part-price"
                    toman={toman}
                    value={form.data.unit_price}
                    onChange={(value) => form.setData('unit_price', value)}
                  />
                </div>
              </div>

              {form.errors.quantity && (
                <p role="alert" className="text-sm text-destructive">
                  {form.errors.quantity}
                </p>
              )}

              {/* `unit_price` is on the same FormRequest and had no field to sit under —
                  a negative or non-integer price was refused silently, on the panel where
                  a technician is pricing a part with a customer waiting. */}
              <FormErrors errors={form.errors} handled={['quantity']} />

              <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                  <PlusIcon className="size-4" aria-hidden />
                  رزرو قطعه
                </Button>
                <Button
                  type="button"

                  variant="ghost"
                  onClick={() => {
                    setPicked(null);
                    form.setData('variant_id', null);
                  }}
                >
                  انصراف
                </Button>
              </div>
            </form>
          )}
        </div>
      )}
    </section>
  );
}
