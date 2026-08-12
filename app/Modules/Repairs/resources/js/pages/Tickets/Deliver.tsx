import { Head, router } from '@inertiajs/react';
import { PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

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

  const settled =
    ticket.prepaid_amount.value + payments.reduce((sum, payment) => sum + payment.amount, 0);
  const due = Math.max(0, total - settled);

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
    <AppShell title={`تحویل دستگاه — ${ticket.code}`}>
      <Head title={`تحویل ${ticket.code}`} />

      <form onSubmit={submit} className="mx-auto max-w-xl space-y-5 pb-24">
        {/* Every error, including the ones that belong to no field (CLAUDE.md). */}
        {Object.keys(errors).length > 0 && (
          <div
            role="alert"
            data-testid="deliver-errors"
            className="space-y-1 rounded-card border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
          >
            <p className="font-medium">تحویل ثبت نشد:</p>
            <ul className="list-inside list-disc">
              {Object.entries(errors).map(([field, message]) => (
                <li key={field}>{message}</li>
              ))}
            </ul>
          </div>
        )}

        {!ticket.can_deliver && (
          <p className="rounded-card border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning">
            تا وقتی دستگاه «آماده تحویل» نشده، تحویل ثبت نمی‌شود.
          </p>
        )}

        <section className="space-y-2 rounded-card border border-border p-4">
          <h2 className="text-sm font-semibold">{ticket.device}</h2>
          <p className="text-2xs text-muted-foreground">
            {ticket.party_name ?? 'مشتری گذری'} · {ticket.code}
          </p>
        </section>

        {ticket.parts.length > 0 && (
          <section className="space-y-2 rounded-card border border-border p-4">
            <h2 className="text-sm font-semibold">قطعات مصرف‌شده</h2>
            <ul className="space-y-1 text-sm">
              {ticket.parts.map((part) => (
                <li key={part.name} className="flex items-baseline justify-between gap-2">
                  <span>
                    {part.name}
                    {part.quantity > 1 && (
                      <>
                        {' × '}
                        <Num value={part.quantity} variant="prose" />
                      </>
                    )}
                  </span>
                  <Money rial={part.unit_price.value * part.quantity} digits="latin" />
                </li>
              ))}
            </ul>
            <p className="text-2xs text-muted-foreground">
              قیمت قطعات هنگام مصرف ثبت شده و اینجا تغییر نمی‌کند.
            </p>
          </section>
        )}

        <section className="space-y-3 rounded-card border border-border p-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">دستمزد و هزینه‌ها</h2>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                setLabour((current) => [
                  ...current,
                  { id: crypto.randomUUID(), description: '', amount: 0 },
                ])
              }
            >
              <PlusIcon className="size-4" aria-hidden />
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
                      current.map((item) => (item.id === row.id ? { ...item, amount: rial } : item))
                    )
                  }
                />
              </div>

              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="حذف ردیف"
                onClick={() => setLabour((current) => current.filter((item) => item.id !== row.id))}
              >
                <XIcon className="size-4" />
              </Button>
            </div>
          ))}
        </section>

        <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
          <div className="flex items-baseline justify-between">
            <dt className="text-muted-foreground">قطعات</dt>
            <dd>
              <Money rial={partsTotal} digits="latin" />
            </dd>
          </div>
          <div className="flex items-baseline justify-between">
            <dt className="text-muted-foreground">دستمزد</dt>
            <dd>
              <Money rial={labourTotal} digits="latin" />
            </dd>
          </div>
          {ticket.prepaid_amount.value > 0 && (
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">پیش‌پرداخت</dt>
              <dd>
                <Money rial={ticket.prepaid_amount.value} digits="latin" />
              </dd>
            </div>
          )}
          <div className="flex items-baseline justify-between border-t border-border pt-2 text-lg font-semibold">
            <dt>قابل پرداخت</dt>
            <dd data-testid="deliver-due">
              <Money rial={due} digits="latin" withUnit />
            </dd>
          </div>
        </dl>

        <section className="space-y-3 rounded-card border border-border p-4">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">پرداخت</h2>
            <Button
              type="button"
              variant="outline"
              size="sm"
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
              <PlusIcon className="size-4" aria-hidden />
              افزودن پرداخت
            </Button>
          </div>

          {payments.map((payment) => {
            const method = methods.find((m) => m.value === payment.method);

            return (
              <div key={payment.id} className="space-y-2 rounded-control border border-border p-3">
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
                                  account_id: methods.find((m) => m.value === value)?.needs_account
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
                    <XIcon className="size-4" />
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

        <section className="space-y-2 rounded-card border border-border p-4">
          <h2 className="text-sm font-semibold">امضای تحویل‌گیرنده</h2>
          <SignaturePad onChange={setSignature} />
        </section>

        <div className="space-y-2">
          <Label htmlFor="warranty">گارانتی تعمیر (روز)</Label>
          <Input
            id="warranty"
            dir="ltr"
            inputMode="numeric"
            className="tabular w-32"
            value={warranty}
            onChange={(event) => setWarranty(event.target.value)}
          />
        </div>

        <div className="fixed inset-x-0 bottom-0 border-t border-border bg-background/95 p-3 backdrop-blur">
          <div className="mx-auto max-w-xl">
            <Button
              type="submit"
              className="h-12 w-full"
              disabled={processing || !ticket.can_deliver || total === 0}
            >
              ثبت تحویل و صدور فاکتور
            </Button>
          </div>
        </div>
      </form>
    </AppShell>
  );
}
