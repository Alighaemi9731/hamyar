import { Head, Link, router } from '@inertiajs/react';
import { RotateCcwIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface ReturnableItem {
  id: number;
  description: string;
  imei: string | null;
  is_serialized: boolean;
  quantity: number;
  returned_quantity: number;
  returnable_quantity: number;
  line_total: MoneyValue;
  unit_refund: MoneyValue;
}

interface Props {
  invoice: {
    id: number;
    number: string | null;
    issued_at: string | null;
    party_name: string | null;
    total: MoneyValue;
  };
  items: ReturnableItem[];
  grades: Array<{ value: string; label: string }>;
}

interface LineDraft {
  quantity: number;
  regrade: string;
  restock: boolean;
}

/**
 * برگشت از فروش — recording what a customer brought back.
 *
 * ## The form shows what is still returnable, not what was sold
 *
 * A line that has already come back in full is shown as done rather than offered again.
 * A form that re-offers the full quantity is a form that refunds the same charger twice,
 * and the person filling it in has no way to know.
 *
 * ## A returned handset is not put back on the shelf by this form
 *
 * The default is that it comes back into the shop as `returned` — present, not sellable.
 * Making it sellable again is a second, deliberate tick, next to the grade the checker
 * gives it, because nine days in somebody's pocket changes what a phone is worth and
 * "back in stock at the old grade" is how a scratched handset gets sold as new.
 */
export default function ReturnCreate({ invoice, items, grades }: Props) {
  const [drafts, setDrafts] = useState<Record<number, LineDraft>>(() =>
    Object.fromEntries(items.map((item) => [item.id, { quantity: 0, regrade: '', restock: false }]))
  );

  // `router.post` rather than `useForm`: the payload is assembled from a keyed draft
  // map rather than held as form state, and `useForm`'s data type cannot express a
  // nested array of lines without being widened until it checks nothing.
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  /*
    A whole line coming back refunds its exact total; a partial one is priced per unit.

    `unit_refund` is rounded up to a whole toman on the server, because a rial-precise
    per-unit share is not a figure that can be shown or paid (ADR 0009). Multiplying that
    rounded number by the full quantity would therefore refund slightly *more* than the
    customer was charged — 10 rial on a line of two. So the full-line case does not use it:
    it uses `line_total`, which is exact.

    "Whole line" means every unit of it, with none returned before. A line that has already
    had one unit back is priced per unit for the rest, where the rounding is in the
    customer's favour by at most nine rial and the shop is the party that should absorb it.
  */
  const refund = items.reduce((sum, item) => {
    const draft = drafts[item.id];

    if (!draft || draft.quantity === 0) {
      return sum;
    }

    const wholeLine = item.returned_quantity === 0 && draft.quantity === item.quantity;

    return sum + (wholeLine ? item.line_total.value : draft.quantity * item.unit_refund.value);
  }, 0);

  const [reason, setReason] = useState('');

  function update(id: number, changes: Partial<LineDraft>): void {
    setDrafts((current) => ({ ...current, [id]: { ...current[id]!, ...changes } }));
  }

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    if (processing) {
      return;
    }

    setProcessing(true);
    setErrors({});

    router.post(
      `/sales/invoices/${invoice.id}/returns`,
      {
        unit: 'rial',
        reason: reason.trim() || null,
        // Every line is sent, including the untouched ones as a zero. The server drops
        // those — leaving three lines alone is the normal shape of a partial return.
        lines: items.map((item) => ({
          item_id: item.id,
          quantity: drafts[item.id]?.quantity ?? 0,
          regrade: drafts[item.id]?.regrade || null,
          restock: drafts[item.id]?.restock ?? false,
        })),
      },
      {
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setProcessing(false),
      }
    );
  }

  const nothingSelected = refund === 0;

  return (
    <AppShell title={`برگشت از فاکتور ${invoice.number ?? ''}`}>
      <Head title="برگشت از فروش" />

      <form onSubmit={submit} className="space-y-6">
        <p className="text-sm text-muted-foreground">
          فاکتور <span className="tabular">{invoice.number}</span> —{' '}
          {formatJalali(invoice.issued_at)}
          {invoice.party_name ? ` — ${invoice.party_name}` : ' — مشتری گذری'}
        </p>

        <div className="space-y-3">
          {items.map((item) => {
            const draft = drafts[item.id]!;
            const done = item.returnable_quantity === 0;

            return (
              <div
                key={item.id}
                className="space-y-3 rounded-card border border-border p-4 aria-disabled:opacity-60"
                aria-disabled={done}
              >
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="min-w-0">
                    <span className="block font-medium">{item.description}</span>
                    <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                      {item.imei && <Num value={item.imei} variant="ltr" />}
                      <span>
                        فروخته‌شده <Num value={item.quantity} variant="prose" />
                      </span>
                      {item.returned_quantity > 0 && (
                        <span className="text-warning">
                          قبلاً برگشتی <Num value={item.returned_quantity} variant="prose" />
                        </span>
                      )}
                    </span>
                  </span>

                  <Money rial={item.line_total.value} digits="latin" />
                </div>

                {done ? (
                  <p className="text-xs text-muted-foreground">
                    این ردیف به‌طور کامل برگشت خورده است.
                  </p>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-2">
                      <Label htmlFor={`qty-${item.id}`}>تعداد برگشتی</Label>
                      <Input
                        id={`qty-${item.id}`}
                        dir="ltr"
                        inputMode="numeric"
                        className="tabular"
                        value={String(draft.quantity)}
                        onChange={(event) => {
                          const typed = Number(event.target.value.replace(/\D/g, '')) || 0;

                          update(item.id, {
                            // Capped at what is left, so the field cannot express a
                            // refund the server is going to refuse.
                            quantity: Math.min(typed, item.returnable_quantity),
                          });
                        }}
                      />
                    </div>

                    {item.is_serialized && (
                      <>
                        <div className="space-y-2">
                          <Label htmlFor={`grade-${item.id}`}>درجه پس از بازبینی</Label>
                          <Select
                            value={draft.regrade}
                            onValueChange={(value) => update(item.id, { regrade: value })}
                          >
                            <SelectTrigger id={`grade-${item.id}`} className="w-full">
                              <SelectValue placeholder="انتخاب کنید" />
                            </SelectTrigger>
                            <SelectContent dir="rtl">
                              {grades.map((grade) => (
                                <SelectItem key={grade.value} value={grade.value}>
                                  {grade.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>

                        <label className="flex items-end gap-2 pb-2 text-sm">
                          <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={draft.restock}
                            onChange={(event) => update(item.id, { restock: event.target.checked })}
                          />
                          <span>بازبینی شد — به موجودی برگردد</span>
                        </label>
                      </>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>

        <div className="space-y-2">
          <Label htmlFor="return-reason">دلیل برگشت</Label>
          <Input
            id="return-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="مثلاً: مشتری از رنگ راضی نبود"
          />
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border p-4">
          <span className="text-sm text-muted-foreground">مبلغ برگشتی</span>
          <span className="text-lg font-semibold" data-testid="return-total">
            <Money rial={refund} digits="latin" withUnit />
          </span>
        </div>

        {/*
          Above the button rather than at the head of the form: this form is as long as the
          invoice, and a refusal announced off-screen is a refusal nobody sees. Nothing here
          is rendered beside a field — `quantity` is capped rather than validated on the
          client — so the region takes the whole bag with no `handled` list.
        */}
        <FormErrors errors={errors} />

        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={nothingSelected || processing}>
            <RotateCcwIcon className="size-4" aria-hidden />
            ثبت برگشت
          </Button>
          <Button asChild variant="ghost">
            <Link href={`/sales/invoices/${invoice.id}`}>انصراف</Link>
          </Button>
        </div>

        <p className="text-2xs text-muted-foreground">
          برگشت، فاکتور اصلی را تغییر نمی‌دهد؛ یک سند اعتباری جداگانه با شماره خودش ثبت می‌شود و
          مبلغ آن به حساب مشتری بستانکار می‌نشیند.
        </p>
      </form>
    </AppShell>
  );
}
