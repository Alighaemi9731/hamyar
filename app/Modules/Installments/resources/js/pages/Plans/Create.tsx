import { Head, Link, router } from '@inertiajs/react';
import { CalendarCheckIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { type PartyOption, PartyPicker } from '@/components/domain/party-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface Props {
  invoice: {
    id: number;
    number: string | null;
    party_name: string | null;
    total: MoneyValue;
    paid_total: MoneyValue;
    financed: MoneyValue;
  };
  defaults: {
    count: number;
    profit_percent: number;
    interval_months: number;
    first_due: string;
  };
}

interface PreviewRow {
  sequence: number;
  due_at: string;
  due_at_jalali: string;
  amount: MoneyValue;
}

interface Preview {
  principal?: MoneyValue;
  profit_amount?: MoneyValue;
  total_payable?: MoneyValue;
  rows?: PreviewRow[];
  error?: string;
}

/**
 * The instalment wizard.
 *
 * ## Nobody signs a schedule they have not seen
 *
 * The question a customer asks is not "what is the total" but "what am I paying on the
 * fifteenth of each month". So the full schedule — every date, every rial — is on screen
 * before anything is written, and it updates as the four fields change.
 *
 * The rows are computed by the server rather than mirrored in the browser, which is the
 * opposite of the POS's choice and right for the same reason: a plan is written once per
 * sale, not a hundred times a day, so one small round trip per edit costs nothing and
 * buys a single implementation of the rounding rule.
 *
 * ## The amount is not a field
 *
 * What gets financed is whatever the invoice still owes after the down payment taken at
 * the till. Offering it as an editable number would let somebody write a schedule that
 * does not match the sale it is attached to.
 */
export default function InstallmentPlanCreate({ invoice, defaults }: Props) {
  const [count, setCount] = useState(defaults.count);
  const [profitPercent, setProfitPercent] = useState(defaults.profit_percent);
  const [intervalMonths, setIntervalMonths] = useState(defaults.interval_months);
  const [firstDue, setFirstDue] = useState<string | null>(null);
  const [guarantor, setGuarantor] = useState<PartyOption | null>(null);
  const [notes, setNotes] = useState('');

  const [preview, setPreview] = useState<Preview | null>(null);
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const firstDueJalali = firstDue
    ? formatJalali(firstDue, { persianDigits: false })
    : defaults.first_due;

  useEffect(() => {
    const controller = new AbortController();

    const query = new URLSearchParams({
      count: String(count),
      profit_percent: String(profitPercent),
      interval_months: String(intervalMonths),
      first_due: firstDueJalali,
    });

    const timer = window.setTimeout(() => {
      fetch(`/installments/invoices/${invoice.id}/plan/preview?${query.toString()}`, {
        signal: controller.signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then((response) => (response.ok ? (response.json() as Promise<Preview>) : null))
        .then((payload) => payload && setPreview(payload))
        .catch(() => {
          /* An aborted preview is us cancelling our own request, not a failure. */
        });
    }, 200);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [count, profitPercent, intervalMonths, firstDueJalali, invoice.id]);

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    if (processing) {
      return;
    }

    setProcessing(true);
    setErrors({});

    router.post(
      `/installments/invoices/${invoice.id}/plan`,
      {
        count,
        profit_percent: profitPercent,
        interval_months: intervalMonths,
        first_due: firstDueJalali,
        guarantor_party_id: guarantor?.id ?? null,
        notes: notes.trim() || null,
      },
      {
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setProcessing(false),
      }
    );
  }

  return (
    <AppShell title={`فروش اقساطی — فاکتور ${invoice.number ?? ''}`}>
      <Head title="فروش اقساطی" />

      <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <div className="space-y-5">
          <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">مبلغ فاکتور</dt>
              <dd>
                <Money rial={invoice.total.value} digits="latin" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">پیش‌پرداخت</dt>
              <dd>
                <Money rial={invoice.paid_total.value} digits="latin" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between border-t border-border pt-2 font-semibold">
              <dt>مبلغ قابل تقسیط</dt>
              <dd data-testid="plan-financed">
                <Money rial={invoice.financed.value} digits="latin" withUnit />
              </dd>
            </div>
            <p className="pt-1 text-2xs text-muted-foreground">
              پیش‌پرداخت در صندوق دریافت شده است؛ آنچه تقسیط می‌شود باقی‌مانده فاکتور است.
            </p>
          </dl>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-2">
              <Label htmlFor="plan-count">تعداد اقساط</Label>
              <Input
                id="plan-count"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={String(count)}
                onChange={(event) =>
                  setCount(Math.max(1, Number(event.target.value.replace(/\D/g, '')) || 1))
                }
                aria-invalid={Boolean(errors.count)}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="plan-profit">درصد سود (تخت)</Label>
              <Input
                id="plan-profit"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={String(profitPercent)}
                onChange={(event) =>
                  setProfitPercent(
                    Math.min(100, Number(event.target.value.replace(/\D/g, '')) || 0)
                  )
                }
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="plan-interval">فاصله اقساط (ماه)</Label>
            <Input
              id="plan-interval"
              dir="ltr"
              inputMode="numeric"
              className="tabular"
              value={String(intervalMonths)}
              onChange={(event) =>
                setIntervalMonths(
                  Math.max(1, Math.min(12, Number(event.target.value.replace(/\D/g, '')) || 1))
                )
              }
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="plan-first-due">سررسید اولین قسط</Label>
            <JDatePicker id="plan-first-due" value={firstDue} onChange={setFirstDue} />
            {errors.first_due && <p className="text-sm text-destructive">{errors.first_due}</p>}
          </div>

          <div className="space-y-2">
            <Label htmlFor="plan-guarantor">ضامن (اختیاری)</Label>
            <PartyPicker id="plan-guarantor" value={guarantor} onChange={setGuarantor} />
          </div>

          <div className="space-y-2">
            <Label htmlFor="plan-notes">توضیحات قرارداد</Label>
            <Input
              id="plan-notes"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
            />
          </div>

          {errors.count && (
            <p role="alert" className="text-sm text-destructive">
              {errors.count}
            </p>
          )}

          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={processing || Boolean(preview?.error)}>
              <CalendarCheckIcon className="size-4" aria-hidden />
              ثبت قرارداد
            </Button>
            <Button asChild variant="ghost">
              <Link href={`/sales/invoices/${invoice.id}`}>انصراف</Link>
            </Button>
          </div>
        </div>

        <div className="space-y-4">
          {preview?.error ? (
            <p className="rounded-card border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning">
              {preview.error}
            </p>
          ) : (
            preview?.rows && (
              <>
                <dl className="grid grid-cols-3 gap-3 rounded-card border border-border p-4 text-sm">
                  <div>
                    <dt className="text-2xs text-muted-foreground">اصل</dt>
                    <dd>
                      <Money rial={preview.principal?.value ?? 0} digits="latin" />
                    </dd>
                  </div>
                  <div>
                    <dt className="text-2xs text-muted-foreground">سود</dt>
                    <dd>
                      <Money rial={preview.profit_amount?.value ?? 0} digits="latin" />
                    </dd>
                  </div>
                  <div>
                    <dt className="text-2xs text-muted-foreground">مجموع اقساط</dt>
                    <dd className="font-semibold" data-testid="plan-total">
                      <Money rial={preview.total_payable?.value ?? 0} digits="latin" />
                    </dd>
                  </div>
                </dl>

                <div className="overflow-x-auto rounded-card border border-border">
                  <table className="w-full text-sm">
                    <caption className="sr-only">پیش‌نمایش اقساط طرح، پیش از ثبت.</caption>
                    <thead className="bg-muted/50 text-2xs text-muted-foreground">
                      <tr>
                        <th scope="col" className="p-3 text-start font-medium">
                          قسط
                        </th>
                        <th scope="col" className="p-3 text-start font-medium">
                          سررسید
                        </th>
                        <th scope="col" className="p-3 text-end font-medium">
                          مبلغ
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.rows.map((row) => (
                        <tr key={row.sequence} className="border-t border-border">
                          <td className="p-3">
                            <Num value={row.sequence} variant="table" />
                          </td>
                          <td className="p-3 tabular">{row.due_at_jalali}</td>
                          <td className="p-3 text-end">
                            <Money rial={row.amount.value} digits="latin" />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <p className="text-2xs text-muted-foreground">
                  باقی‌مانده تقسیم روی قسط آخر نشسته است تا مجموع اقساط دقیقاً برابر مبلغ قرارداد
                  باشد.
                </p>
              </>
            )
          )}
        </div>
      </form>
    </AppShell>
  );
}
