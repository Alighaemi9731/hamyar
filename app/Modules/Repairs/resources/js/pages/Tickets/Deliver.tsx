import { Head, router } from '@inertiajs/react';
import { PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { MoneyLadder, MoneyRow } from '@/components/domain/money-ladder';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { MoneyField } from '@/components/domain/money-field';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import type { MoneyValue } from '@/types';

import { SignaturePad } from '../../pos/signature-pad';

interface Props {
  ticket: {
    id: number;
    code: string;
    device: string;
    party_name: string | null;
    status: string;
    can_deliver: boolean;
    prepaid_amount: MoneyValue;
    approved_amount: MoneyValue | null;
    estimate_amount: MoneyValue;
    parts: Array<{ name: string; quantity: number; unit_price: MoneyValue }>;
  };
  accounts: Array<{ id: number; name: string; type: string; is_default: boolean }>;
  payment_methods: Array<{
    value: string;
    label: string;
    needs_account: boolean;
    needs_reference: boolean;
  }>;
}

interface Labour {
  id: string;
  description: string;
  amount: number;
}

interface Payment {
  id: string;
  method: string;
  amount: number;
  account_id: number | null;
  reference: string;
}

/**
 * Handing the device back.
 *
 * ## The parts are shown but not editable
 *
 * They were priced when they were fitted, and that is the figure the customer was quoted
 * against. Making them editable here would let somebody change what a part cost after the
 * work was approved — a different conversation, and one that belongs on the ticket rather
 * than at the counter with the customer waiting for their phone.
 *
 * ## Settlement is the Phase 5 payment box, not a copy of it
 *
 * The rows post to `DeliverTicket`, which builds a real `SalesInvoice` and pushes it
 * through the same writer and finaliser the till uses. Split payments, the change clamp,
 * the account guard and the ledger posting all come from there. A second payment UI would
 * be a second place for the arithmetic to drift.
 *
 * ## The signature is captured with a finger
 *
 * See `SignaturePad`. It is optional deliberately — a shop with no touchscreen should
 * still be able to hand a phone back.
 *
 * ## The approved figure is shown, and going over it is said out loud
 *
 * `approved_amount` and `estimate_amount` arrived on the wire from the first day and were
 * rendered nowhere. The whole quote-approval flow — quote the customer, wait, record what
 * they agreed to — exists so a shop does not bill past what was agreed, and this is the
 * one screen where that is decided. Leaving the figure off meant the check happened in
 * somebody's memory, with the customer standing there.
 *
 * So the settlement names it, and the moment the bill passes it the screen says so. A
 * warning, not a block: a customer can approve more over the phone, and refusing the
 * delivery would strand a repaired device behind a screen the shop cannot get past. What
 * it must not do is stay quiet.
 *
 * ## The settlement shows why the figure moved
 *
 * It listed parts, labour, the prepayment and «قابل پرداخت» — but never the sum of the
 * bill, and never the payments being entered. So typing a payment made the amount due drop
 * with no row on screen accounting for it. Every rung of the arithmetic is now present,
 * which is the whole point of a ladder somebody checks by eye.
 *
 * ## The action bar is sticky, not fixed
 *
 * It was `fixed inset-x-0 bottom-0` with a `max-w-xl` centred inside — centred on the
 * *viewport*, while the form it submits is centred in the content column beside a 288px
 * sidebar. Measured at 1280: the form ran 208–784 and its own submit button 352–928, so
 * the primary action of the screen sat **144px to the side of the form**, on a bar running
 * under the navigation. `sticky` inside the form gets both right by construction, with no
 * width arithmetic for a later layout change to falsify.
 */
export default function TicketDeliver({ ticket, accounts, payment_methods: methods }: Props) {
  const settings = useTenantSettings();
  const toman = settings.currency_display === 'toman';

  const [labour, setLabour] = useState<Labour[]>([
    { id: crypto.randomUUID(), description: 'دستمزد تعمیر', amount: 0 },
  ]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [signature, setSignature] = useState<Blob | null>(null);
  const [warranty, setWarranty] = useState('30');
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const partsTotal = ticket.parts.reduce(
    (sum, part) => sum + part.unit_price.value * part.quantity,
    0
  );
  const labourTotal = labour.reduce((sum, row) => sum + row.amount, 0);
  const total = partsTotal + labourTotal;

  const paidNow = payments.reduce((sum, payment) => sum + payment.amount, 0);
  const settled = ticket.prepaid_amount.value + paidNow;
  const due = Math.max(0, total - settled);

  // What the customer actually agreed to, if they were ever asked. `approved_amount` is
  // written by the approval flow; before that there is only the shop's own estimate, which
  // nobody agreed to and which is therefore not a ceiling.
  const approved = ticket.approved_amount?.value ?? null;
  const overApproved = approved !== null && total > approved;

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    setProcessing(true);
    setErrors({});

    const payload = new FormData();

    payload.append('unit', 'rial');
    payload.append('warranty_days', toLatinDigits(warranty).replace(/\D/g, '') || '0');

    labour
      .filter((row) => row.amount > 0)
      .forEach((row, index) => {
        payload.append(`labour[${index}][description]`, row.description || 'دستمزد');
        payload.append(`labour[${index}][amount]`, String(row.amount));
      });

    payments
      .filter((row) => row.amount > 0)
      .forEach((row, index) => {
        payload.append(`payments[${index}][method]`, row.method);
        payload.append(`payments[${index}][amount]`, String(row.amount));
        if (row.account_id !== null) {
          payload.append(`payments[${index}][account_id]`, String(row.account_id));
        }
        if (row.reference.trim()) {
          payload.append(`payments[${index}][reference]`, row.reference.trim());
        }
      });

    if (signature) {
      payload.append('signature', signature, 'signature.png');
    }

    router.post(`/repairs/tickets/${ticket.id}/deliver`, payload, {
      forceFormData: true,
      onError: (received) => setErrors(received as Record<string, string>),
      onFinish: () => setProcessing(false),
    });
  }

  return (
    <AppShell
      header={
        <div className="mx-auto max-w-xl">
          <PageHeader
            eyebrow="تحویل دستگاه"
            title={ticket.device}
            back={{ href: `/repairs/tickets/${ticket.id}`, label: 'بازگشت به تیکت' }}
            meta={
              <>
                <StatusBadge status={ticket.status} />
                <span className="text-sm text-muted-foreground">
                  <Num value={ticket.code} variant="ltr" />
                </span>
                <span className="text-sm text-muted-foreground">
                  {ticket.party_name ?? 'مشتری گذری'}
                </span>
              </>
            }
          />
        </div>
      }
    >
      <Head title={`تحویل ${ticket.code}`} />

      <form onSubmit={submit} className="mx-auto max-w-xl space-y-8">
        {/* Every key, including the ones that belong to no field (CLAUDE.md). */}
        <FormErrors errors={errors} />

        {!ticket.can_deliver && (
          <p
            role="alert"
            className="rounded-card border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning"
          >
            تا وقتی دستگاه «آماده تحویل» نشده، تحویل ثبت نمی‌شود.
          </p>
        )}

        {ticket.parts.length > 0 && (
          <Card asChild>
            <section className="space-y-4">
              <h2 className="text-sm font-semibold">قطعات مصرف‌شده</h2>

              <MoneyLadder>
                {ticket.parts.map((part) => (
                  <MoneyRow
                    key={part.name}
                    label={part.quantity > 1 ? `${part.name} × ${part.quantity}` : part.name}
                    rial={part.unit_price.value * part.quantity}
                  />
                ))}
              </MoneyLadder>

              <p className="text-2xs text-muted-foreground">
                قیمت قطعات هنگام مصرف ثبت شده و اینجا تغییر نمی‌کند.
              </p>
            </section>
          </Card>
        )}

        <Card asChild>
          <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">دستمزد و هزینه‌ها</h2>
              <Button
                type="button"
                variant="outline"
                onClick={() =>
                  setLabour((current) => [
                    ...current,
                    { id: crypto.randomUUID(), description: '', amount: 0 },
                  ])
                }
              >
                <PlusIcon aria-hidden />
                افزودن
              </Button>
            </div>

            {labour.map((row) => (
              <div key={row.id} className="flex items-end gap-2">
                <div className="min-w-0 flex-1 space-y-2">
                  <Label htmlFor={`labour-${row.id}`} className="text-2xs">
                    شرح
                  </Label>
                  <Input
                    id={`labour-${row.id}`}
                    value={row.description}
                    onChange={(event) =>
                      setLabour((current) =>
                        current.map((item) =>
                          item.id === row.id ? { ...item, description: event.target.value } : item
                        )
                      )
                    }
                  />
                </div>

                <div className="w-36 space-y-2">
                  <Label className="text-2xs">مبلغ</Label>
                  <MoneyField
                    toman={toman}
                    value={row.amount}
                    aria-label={`مبلغ ${row.description || 'دستمزد'}`}
                    onChange={(rial) =>
                      setLabour((current) =>
                        current.map((item) =>
                          item.id === row.id ? { ...item, amount: rial } : item
                        )
                      )
                    }
                  />
                </div>

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="حذف ردیف"
                  onClick={() =>
                    setLabour((current) => current.filter((item) => item.id !== row.id))
                  }
                >
                  <XIcon />
                </Button>
              </div>
            ))}
          </section>
        </Card>

        <Card asChild>
          <section className="space-y-4">
            <h2 className="text-sm font-semibold">تسویه</h2>

            <MoneyLadder>
              <MoneyRow label="قطعات" rial={partsTotal} />
              <MoneyRow label="دستمزد" rial={labourTotal} />
              <MoneyRow label="جمع کل" rial={total} divider />

              {/*
                Deductions carry a minus and no colour. `signed` paints a negative
                `text-danger`, and a prepayment is not a loss — it is money the shop is
                already holding. Red here would read as a problem where there is none.
              */}
              {ticket.prepaid_amount.value > 0 && (
                <MoneyRow label="پیش‌پرداخت" rial={-ticket.prepaid_amount.value} />
              )}
              {paidNow > 0 && <MoneyRow label="پرداخت این مرحله" rial={-paidNow} />}

              <MoneyRow label="قابل پرداخت" rial={due} tone="text-foreground" divider />
            </MoneyLadder>

            {/*
              The unit goes on its own line, never inline on a rung: «۸٬۶۶۸٬۰۰۰ تومان» does
              not fit the ladder's fixed 9ch value track and pushes the card sideways on a
              phone. Same reasoning as the treasury day-close.
            */}
            <p className="text-2xs text-muted-foreground" data-testid="deliver-due">
              <Money rial={due} digits="latin" withUnit unitPlacement="block" />
            </p>

            {approved !== null && (
              <p
                className={
                  overApproved
                    ? 'rounded-control border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning'
                    : 'text-2xs text-muted-foreground'
                }
                role={overApproved ? 'alert' : undefined}
              >
                {overApproved ? (
                  <>
                    این صورت‌حساب از مبلغ تأییدشدهٔ مشتری (
                    <Money rial={approved} digits="latin" />) بیشتر است. پیش از تحویل، تأیید تازه
                    بگیرید.
                  </>
                ) : (
                  <>
                    مبلغ تأییدشدهٔ مشتری: <Money rial={approved} digits="latin" />
                  </>
                )}
              </p>
            )}
          </section>
        </Card>

        <Card asChild>
          <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">پرداخت</h2>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  const fallback = accounts.find((a) => a.is_default) ?? accounts[0];

                  setPayments((current) => [
                    ...current,
                    {
                      id: crypto.randomUUID(),
                      method: 'cash',
                      amount: due,
                      account_id: fallback?.id ?? null,
                      reference: '',
                    },
                  ]);
                }}
              >
                <PlusIcon aria-hidden />
                افزودن پرداخت
              </Button>
            </div>

            {payments.length === 0 && (
              <p className="text-xs text-muted-foreground">
                اگر مشتری همین حالا پرداخت می‌کند، ردیف پرداخت اضافه کنید. بدون آن، مبلغ روی حساب
                مشتری بدهکار می‌ماند.
              </p>
            )}

            {payments.map((payment) => {
              const method = methods.find((m) => m.value === payment.method);

              return (
                <div
                  key={payment.id}
                  className="space-y-2 rounded-control border border-border p-3"
                >
                  <div className="flex items-end gap-2">
                    <div className="min-w-0 flex-1 space-y-2">
                      <Label className="text-2xs">روش</Label>
                      <Select
                        value={payment.method}
                        onValueChange={(value) =>
                          setPayments((current) =>
                            current.map((row) =>
                              row.id === payment.id
                                ? {
                                    ...row,
                                    method: value,
                                    account_id: methods.find((m) => m.value === value)
                                      ?.needs_account
                                      ? row.account_id
                                      : null,
                                  }
                                : row
                            )
                          )
                        }
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent dir="rtl">
                          {methods.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                              {option.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="w-36 space-y-2">
                      <Label className="text-2xs">مبلغ</Label>
                      <MoneyField
                        toman={toman}
                        value={payment.amount}
                        aria-label="مبلغ پرداخت"
                        onChange={(rial) =>
                          setPayments((current) =>
                            current.map((row) =>
                              row.id === payment.id ? { ...row, amount: rial } : row
                            )
                          )
                        }
                      />
                    </div>

                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label="حذف پرداخت"
                      onClick={() =>
                        setPayments((current) => current.filter((row) => row.id !== payment.id))
                      }
                    >
                      <XIcon />
                    </Button>
                  </div>

                  {method?.needs_account && (
                    <Select
                      value={payment.account_id === null ? '' : String(payment.account_id)}
                      onValueChange={(value) =>
                        setPayments((current) =>
                          current.map((row) =>
                            row.id === payment.id ? { ...row, account_id: Number(value) } : row
                          )
                        )
                      }
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="صندوق یا حساب" />
                      </SelectTrigger>
                      <SelectContent dir="rtl">
                        {accounts.map((account) => (
                          <SelectItem key={account.id} value={String(account.id)}>
                            {account.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </div>
              );
            })}
          </section>
        </Card>

        <Card asChild>
          <section className="space-y-4">
            <h2 className="text-sm font-semibold">امضای تحویل‌گیرنده</h2>
            <SignaturePad onChange={setSignature} />
          </section>
        </Card>

        <Card asChild>
          <section className="space-y-2">
            <Label htmlFor="warranty">گارانتی تعمیر (روز)</Label>
            <Input
              id="warranty"
              dir="ltr"
              inputMode="numeric"
              className="tabular w-32"
              value={warranty}
              onChange={(event) => setWarranty(event.target.value)}
            />
          </section>
        </Card>

        {/*
          Sticky inside the form, so it spans and aligns with the form rather than the
          viewport. `-mx-4 px-4` lets the rule bleed to the edges of the content column on a
          phone while the button stays on the form's own measure.
        */}
        <div className="sticky bottom-0 z-sticky -mx-4 border-t border-border bg-background/95 px-4 py-3 backdrop-blur">
          <Button
            type="submit"
            size="lg"
            className="w-full"
            disabled={processing || !ticket.can_deliver || total === 0}
          >
            ثبت تحویل و صدور فاکتور
          </Button>
        </div>
      </form>
    </AppShell>
  );
}
