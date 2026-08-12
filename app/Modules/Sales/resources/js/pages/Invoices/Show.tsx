import { Head, Link, useForm } from '@inertiajs/react';
import { BanIcon, PrinterIcon, RotateCcwIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface InvoiceItem {
  id: number;
  description: string;
  imei: string | null;
  quantity: number;
  unit_price: MoneyValue;
  discount_amount: MoneyValue;
  vat_amount: MoneyValue;
  line_total: MoneyValue;
  warranty_months: number | null;
  returned_quantity: number;
}

interface InvoicePayment {
  id: number;
  method: string;
  method_label: string;
  account_name: string | null;
  amount: MoneyValue;
  tendered_amount: MoneyValue | null;
  change: MoneyValue;
  reference: string | null;
  received_at: string;
}

/**
 * Named rather than a `Record<string, MoneyValue>`.
 *
 * The loose map compiled, but every read of it was `MoneyValue | undefined` — so the
 * page had to guard figures that the server always sends, and a genuinely missing one
 * would have been indistinguishable from a typo in a key.
 */
interface InvoiceTotals {
  subtotal: MoneyValue;
  discount_amount: MoneyValue;
  vat_amount: MoneyValue;
  shipping_amount: MoneyValue;
  rounding_adjustment: MoneyValue;
  total: MoneyValue;
  paid_total: MoneyValue;
  outstanding: MoneyValue;
}

interface Props {
  invoice: {
    id: number;
    number: string | null;
    type: string;
    status: string;
    status_label: string;
    issued_at: string | null;
    voided_at: string | null;
    void_reason: string | null;
    notes: string | null;
    branch_name: string;
    salesperson_name: string | null;
    party: { id: number; name: string; mobile: string | null } | null;
    items: InvoiceItem[];
    payments: InvoicePayment[];
    totals: InvoiceTotals;
    returns: Array<{
      id: number;
      number: string;
      total: MoneyValue;
      reason: string | null;
      returned_at: string;
    }>;
    trade_in: {
      device_name: string;
      imei1: string | null;
      agreed_price: MoneyValue;
      grade: string | null;
    } | null;
  };
  profit: {
    revenue: MoneyValue;
    cost: MoneyValue;
    profit: MoneyValue;
    margin_percent: number;
    lines: Array<{
      id: number;
      description: string;
      revenue: MoneyValue;
      cost: MoneyValue;
      profit: MoneyValue;
    }>;
  } | null;
  party_balance: MoneyValue | null;
  can: { void: boolean; return: boolean; create: boolean };
}

/**
 * One sale, after the fact.
 *
 * The page a shop opens when a customer comes back — to reprint, to return, to argue
 * about what was charged. So every figure that produced the total is on it, including
 * the rounding adjustment, which exists precisely so that nobody has to explain a gap
 * between the lines and the total from memory.
 *
 * Profit is absent, not zeroed, for staff without `sales.view_profit`.
 */
export default function InvoiceShow({ invoice, profit, party_balance: partyBalance, can }: Props) {
  const [voiding, setVoiding] = useState(false);
  const voidForm = useForm({ reason: '' });

  const isFinal = invoice.status === 'final';

  return (
    <AppShell
      title={invoice.number ? `فاکتور ${invoice.number}` : 'پیش‌نویس فاکتور'}
      actions={
        <div className="flex flex-wrap items-center gap-2">
          {isFinal && (
            <>
              <Button asChild variant="outline">
                <a
                  href={`/sales/invoices/${invoice.id}/print/thermal80`}
                  target="_blank"
                  rel="noreferrer"
                >
                  <PrinterIcon className="size-4" aria-hidden />
                  رسید حرارتی
                </a>
              </Button>
              <Button asChild variant="outline">
                <a href={`/sales/invoices/${invoice.id}/print/a5`} target="_blank" rel="noreferrer">
                  چاپ A5
                </a>
              </Button>
              <Button asChild variant="outline">
                <a href={`/sales/invoices/${invoice.id}/print/a4`} target="_blank" rel="noreferrer">
                  چاپ A4
                </a>
              </Button>
            </>
          )}

          {isFinal && can.return && (
            <Button asChild variant="outline">
              <Link href={`/sales/invoices/${invoice.id}/returns/create`}>
                <RotateCcwIcon className="size-4" aria-hidden />
                برگشت از فروش
              </Link>
            </Button>
          )}

          {isFinal && can.void && (
            <Button type="button" variant="outline" onClick={() => setVoiding((open) => !open)}>
              <BanIcon className="size-4" aria-hidden />
              ابطال
            </Button>
          )}
        </div>
      }
    >
      <Head title={invoice.number ? `فاکتور ${invoice.number}` : 'فاکتور'} />

      {voiding && (
        <form
          className="mb-4 space-y-3 rounded-card border border-destructive/40 bg-destructive/5 p-4"
          onSubmit={(event) => {
            event.preventDefault();
            voidForm.post(`/sales/invoices/${invoice.id}/void`, {
              onSuccess: () => setVoiding(false),
            });
          }}
        >
          <div className="space-y-2">
            <Label htmlFor="void-reason">دلیل ابطال</Label>
            <Input
              id="void-reason"
              autoFocus
              value={voidForm.data.reason}
              onChange={(event) => voidForm.setData('reason', event.target.value)}
              aria-invalid={Boolean(voidForm.errors.reason)}
            />
            {voidForm.errors.reason && (
              <p className="text-sm text-destructive">{voidForm.errors.reason}</p>
            )}
            <p className="text-2xs text-muted-foreground">
              کالاها به انبار برمی‌گردند و اسناد حسابداری برگشت می‌خورند. شماره فاکتور حفظ می‌شود.
            </p>
          </div>

          <div className="flex gap-2">
            <Button type="submit" variant="destructive" disabled={voidForm.processing}>
              ابطال فاکتور
            </Button>
            <Button type="button" variant="ghost" onClick={() => setVoiding(false)}>
              انصراف
            </Button>
          </div>
        </form>
      )}

      {invoice.status === 'void' && (
        <p className="mb-4 rounded-card border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          این فاکتور در {formatJalali(invoice.voided_at)} ابطال شده است
          {invoice.void_reason ? ` — ${invoice.void_reason}` : ''}.
        </p>
      )}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="space-y-6">
          <div className="overflow-x-auto rounded-card border border-border">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-2xs text-muted-foreground">
                <tr>
                  <th scope="col" className="p-3 text-start font-medium">
                    شرح
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    تعداد
                  </th>
                  <th scope="col" className="p-3 text-end font-medium">
                    قیمت واحد
                  </th>
                  <th scope="col" className="p-3 text-end font-medium">
                    تخفیف
                  </th>
                  <th scope="col" className="p-3 text-end font-medium">
                    جمع
                  </th>
                </tr>
              </thead>

              <tbody>
                {invoice.items.map((item) => (
                  <tr key={item.id} className="border-t border-border align-top">
                    <td className="p-3">
                      <span className="block">{item.description}</span>
                      <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                        {item.imei && <Num value={item.imei} variant="ltr" />}
                        {item.warranty_months !== null && (
                          <span>
                            گارانتی <Num value={item.warranty_months} variant="prose" /> ماه
                          </span>
                        )}
                        {item.returned_quantity > 0 && (
                          <span className="text-warning">
                            <Num value={item.returned_quantity} variant="prose" /> عدد مرجوع شده
                          </span>
                        )}
                      </span>
                    </td>
                    <td className="p-3">
                      <Num value={item.quantity} variant="table" />
                    </td>
                    <td className="p-3 text-end">
                      <Money rial={item.unit_price.value} digits="latin" />
                    </td>
                    <td className="p-3 text-end text-muted-foreground">
                      {item.discount_amount.value > 0 ? (
                        <Money rial={item.discount_amount.value} digits="latin" />
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="p-3 text-end font-medium">
                      <Money rial={item.line_total.value} digits="latin" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <section className="space-y-2">
            <h2 className="text-sm font-semibold">پرداخت‌ها</h2>

            {invoice.payments.length === 0 ? (
              <p className="rounded-control border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">
                پرداختی ثبت نشده — کل مبلغ به حساب مشتری بدهکار شده است.
              </p>
            ) : (
              <ul className="space-y-2">
                {invoice.payments.map((payment) => (
                  <li
                    key={payment.id}
                    className="flex flex-wrap items-baseline justify-between gap-2 rounded-control border border-border px-3 py-2 text-sm"
                  >
                    <span className="flex flex-wrap items-baseline gap-x-2">
                      <span className="font-medium">{payment.method_label}</span>
                      {payment.account_name && (
                        <span className="text-2xs text-muted-foreground">
                          {payment.account_name}
                        </span>
                      )}
                      {payment.reference && (
                        <Num
                          value={payment.reference}
                          variant="ltr"
                          className="text-2xs text-muted-foreground"
                        />
                      )}
                    </span>

                    <span className="flex flex-wrap items-baseline gap-x-3">
                      {payment.tendered_amount && (
                        <span className="text-2xs text-muted-foreground">
                          دریافتی <Money rial={payment.tendered_amount.value} digits="latin" /> —
                          باقی‌مانده <Money rial={payment.change.value} digits="latin" />
                        </span>
                      )}
                      <Money rial={payment.amount.value} digits="latin" />
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {invoice.trade_in && (
            <section className="space-y-2">
              <h2 className="text-sm font-semibold">معاوضه</h2>
              <div className="rounded-control border border-border px-3 py-2 text-sm">
                <span className="font-medium">{invoice.trade_in.device_name}</span>
                {invoice.trade_in.imei1 && (
                  <Num
                    value={invoice.trade_in.imei1}
                    variant="ltr"
                    className="ms-2 text-2xs text-muted-foreground"
                  />
                )}
                <span className="ms-2">
                  <Money rial={invoice.trade_in.agreed_price.value} digits="latin" withUnit />
                </span>
              </div>
            </section>
          )}

          {invoice.returns.length > 0 && (
            <section className="space-y-2">
              <h2 className="text-sm font-semibold">برگشت‌ها</h2>
              <ul className="space-y-2">
                {invoice.returns.map((salesReturn) => (
                  <li
                    key={salesReturn.id}
                    className="flex flex-wrap items-baseline justify-between gap-2 rounded-control border border-border px-3 py-2 text-sm"
                  >
                    <span className="tabular font-medium">{salesReturn.number}</span>
                    <span className="text-2xs text-muted-foreground">
                      {formatJalali(salesReturn.returned_at)}
                      {salesReturn.reason ? ` — ${salesReturn.reason}` : ''}
                    </span>
                    <Money rial={salesReturn.total.value} digits="latin" />
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <aside className="space-y-5">
          <dl className="space-y-2 rounded-card border border-border p-4 text-sm">
            <Row label="وضعیت">
              <StatusBadge status={invoice.status} />
            </Row>
            <Row label="تاریخ">{formatJalali(invoice.issued_at)}</Row>
            <Row label="شعبه">{invoice.branch_name}</Row>
            {invoice.salesperson_name && <Row label="فروشنده">{invoice.salesperson_name}</Row>}
            <Row label="مشتری">
              {invoice.party ? (
                <Link
                  href={`/crm/parties/${invoice.party.id}`}
                  className="text-primary hover:underline"
                >
                  {invoice.party.name}
                </Link>
              ) : (
                'مشتری گذری'
              )}
            </Row>
            {partyBalance && (
              <Row label="مانده حساب مشتری">
                {/* بدهکار/بستانکار rather than a sign: a minus needs the reader to
                    remember which direction is which, and these two words are what an
                    Iranian bookkeeper already reads on a statement. */}
                {partyBalance.value === 0 ? (
                  'تسویه'
                ) : (
                  <span className={cn(partyBalance.value > 0 && 'text-warning')}>
                    {partyBalance.value > 0 ? 'بدهکار ' : 'بستانکار '}
                    <Money rial={Math.abs(partyBalance.value)} digits="latin" />
                  </span>
                )}
              </Row>
            )}
          </dl>

          <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
            <MoneyRow label="جمع کالاها" money={invoice.totals.subtotal} />
            {invoice.totals.discount_amount.value > 0 && (
              <MoneyRow label="تخفیف فاکتور" money={invoice.totals.discount_amount} />
            )}
            {invoice.totals.vat_amount.value > 0 && (
              <MoneyRow label="مالیات" money={invoice.totals.vat_amount} />
            )}
            {invoice.totals.shipping_amount.value > 0 && (
              <MoneyRow label="ارسال" money={invoice.totals.shipping_amount} />
            )}
            {invoice.totals.rounding_adjustment.value !== 0 && (
              <MoneyRow label="گرد کردن" money={invoice.totals.rounding_adjustment} />
            )}

            <div className="flex items-baseline justify-between border-t border-border pt-2 text-base font-semibold">
              <dt>مبلغ کل</dt>
              <dd data-testid="invoice-total">
                <Money rial={invoice.totals.total.value} digits="latin" withUnit />
              </dd>
            </div>

            <MoneyRow label="پرداخت‌شده" money={invoice.totals.paid_total} />

            {invoice.totals.outstanding.value > 0 && (
              <div className="flex items-baseline justify-between text-warning">
                <dt>باقی‌مانده</dt>
                <dd>
                  <Money rial={invoice.totals.outstanding.value} digits="latin" withUnit />
                </dd>
              </div>
            )}
          </dl>

          {profit && (
            <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
              <h2 className="mb-1 text-sm font-semibold">سود این فاکتور</h2>
              <MoneyRow label="فروش (بدون مالیات)" money={profit.revenue} />
              <MoneyRow label="بهای تمام‌شده" money={profit.cost} />
              <div className="flex items-baseline justify-between border-t border-border pt-2 font-semibold">
                <dt>سود</dt>
                <dd data-testid="invoice-profit">
                  <Money rial={profit.profit.value} digits="latin" withUnit signed />
                </dd>
              </div>
              <div className="flex items-baseline justify-between text-2xs text-muted-foreground">
                <dt>حاشیه سود</dt>
                <dd>
                  <Num value={profit.margin_percent} variant="table" />٪
                </dd>
              </div>
            </dl>
          )}

          {invoice.notes && (
            <div className="rounded-card border border-border p-4 text-sm">
              <h2 className="mb-1 text-sm font-semibold">توضیحات</h2>
              <p className="text-muted-foreground">{invoice.notes}</p>
            </div>
          )}
        </aside>
      </div>
    </AppShell>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-2">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end">{children}</dd>
    </div>
  );
}

function MoneyRow({ label, money }: { label: string; money: MoneyValue }) {
  return (
    <div className="flex items-baseline justify-between">
      <dt className="text-muted-foreground">{label}</dt>
      <dd>
        <Money rial={money.value} digits="latin" />
      </dd>
    </div>
  );
}
