import { Head, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

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
import { printSheet } from '@/components/domain/print-layout';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';
import { useState } from 'react';

interface PaymentRow {
  method: string;
  label: string;
  settles_now: boolean;
  count: number;
  amount: MoneyValue;
}

interface Props {
  date: string;
  branch: { id: number; name: string } | null;
  branches: Array<{ id: number; name: string }>;
  report: {
    invoice_count: number;
    void_count: number;
    return_count: number;
    gross: MoneyValue;
    discount: MoneyValue;
    vat: MoneyValue;
    shipping: MoneyValue;
    rounding: MoneyValue;
    net: MoneyValue;
    refunded: MoneyValue;
    credit_extended: MoneyValue;
    expected_cash: MoneyValue;
    payments: PaymentRow[];
    accounts: Array<{ account_id: number | null; name: string; amount: MoneyValue }>;
    profit: {
      revenue: MoneyValue;
      cost: MoneyValue;
      profit: MoneyValue;
      margin_percent: number;
      returned_revenue: MoneyValue;
    } | null;
  };
}

/**
 * گزارش Z — the end of the day at the counter.
 *
 * ## The expected-cash figure is the point of the page
 *
 * Somebody is holding a stack of notes and needs to know what it should come to. That
 * number gets the most visual weight on the screen, and everything above it exists to
 * explain it: what came in as cash, what went back out as a refund, and what settled by
 * means that put nothing in the drawer at all.
 *
 * ## Methods that do not touch the till are marked, not hidden
 *
 * A cheque and a trade-in both settle an invoice and neither adds a note to the drawer.
 * Dropping them would make the day's sales stop adding up; mixing them into the cash
 * line would make the drawer look wrong every evening. So they are listed with the
 * distinction stated on the row.
 *
 * ## Every method appears, including the ones nobody used
 *
 * A row that vanishes on a day with no cheques is a row people stop looking for, and the
 * report changes shape from one day to the next.
 */
export default function DailyClose({ date, branch, branches, report }: Props) {
  const [draftDate, setDraftDate] = useState(date);

  function visit(changes: Record<string, string | number | null>): void {
    router.get(
      '/sales/close',
      { date, branch_id: branch?.id ?? null, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      title="گزارش Z — بستن صندوق"
      actions={
        <Button type="button" variant="outline" onClick={printSheet}>
          <PrinterIcon className="size-4" aria-hidden />
          چاپ
        </Button>
      }
    >
      <Head title="گزارش Z" />

      <div className="no-print mb-6 flex flex-wrap items-end gap-3">
        <div className="space-y-2">
          <Label htmlFor="close-date">تاریخ</Label>
          <form
            onSubmit={(event) => {
              event.preventDefault();
              visit({ date: toLatinDigits(draftDate) });
            }}
          >
            <Input
              id="close-date"
              dir="ltr"
              className="tabular w-40"
              value={draftDate}
              onChange={(event) => setDraftDate(event.target.value)}
              placeholder="1405/06/15"
            />
          </form>
        </div>

        {branches.length > 1 && (
          <div className="space-y-2">
            <Label htmlFor="close-branch">شعبه</Label>
            <Select
              value={branch === null ? 'all' : String(branch.id)}
              onValueChange={(value) => visit({ branch_id: value === 'all' ? '' : value })}
            >
              <SelectTrigger id="close-branch" className="w-48">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value="all">همه شعبه‌ها</SelectItem>
                {branches.map((option) => (
                  <SelectItem key={option.id} value={String(option.id)}>
                    {option.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      <div className="mb-6 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm text-muted-foreground">
        <span className="tabular">{date}</span>
        <span>{branch?.name ?? 'همه شعبه‌ها'}</span>
        <span>
          <Num value={report.invoice_count} variant="prose" /> فاکتور
        </span>
        {report.void_count > 0 && (
          <span className="text-destructive">
            <Num value={report.void_count} variant="prose" /> ابطال
          </span>
        )}
        {report.return_count > 0 && (
          <span className="text-warning">
            <Num value={report.return_count} variant="prose" /> برگشت
          </span>
        )}
      </div>

      {/* The number somebody is counting notes against. */}
      <div className="mb-6 rounded-card border border-border bg-muted/30 p-6">
        <p className="text-sm text-muted-foreground">موجودی مورد انتظار صندوق</p>
        <p className="mt-1 text-3xl font-bold" data-testid="expected-cash">
          <Money rial={report.expected_cash.value} digits="latin" withUnit />
        </p>
        <p className="mt-2 text-2xs text-muted-foreground">
          دریافت نقدی امروز، منهای وجه نقدی که بابت برگشت از فروش به مشتری گذری پرداخت شده است. چک،
          نسیه و معاوضه وارد این عدد نمی‌شوند.
        </p>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <section className="space-y-2">
          <h2 className="text-sm font-semibold">فروش</h2>
          <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
            <Row label="جمع کالاها" money={report.gross} />
            <Row label="تخفیف" money={report.discount} />
            <Row label="مالیات" money={report.vat} />
            <Row label="ارسال" money={report.shipping} />
            <Row label="گرد کردن" money={report.rounding} />
            <div className="flex items-baseline justify-between border-t border-border pt-2 font-semibold">
              <dt>فروش خالص</dt>
              <dd data-testid="net-sales">
                <Money rial={report.net.value} digits="latin" withUnit />
              </dd>
            </div>
            {report.refunded.value > 0 && <Row label="برگشت نقدی" money={report.refunded} />}
            {report.credit_extended.value > 0 && (
              <div className="flex items-baseline justify-between text-warning">
                <dt>نسیه داده‌شده امروز</dt>
                <dd>
                  <Money rial={report.credit_extended.value} digits="latin" />
                </dd>
              </div>
            )}
          </dl>
        </section>

        <section className="space-y-2">
          <h2 className="text-sm font-semibold">روش‌های پرداخت</h2>
          <div className="overflow-x-auto rounded-card border border-border">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-2xs text-muted-foreground">
                <tr>
                  <th scope="col" className="p-3 text-start font-medium">
                    روش
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    تعداد
                  </th>
                  <th scope="col" className="p-3 text-end font-medium">
                    مبلغ
                  </th>
                </tr>
              </thead>
              <tbody>
                {report.payments.map((row) => (
                  <tr
                    key={row.method}
                    className={cn(
                      'border-t border-border',
                      row.amount.value === 0 && 'text-muted-foreground'
                    )}
                  >
                    <td className="p-3">
                      {row.label}
                      {!row.settles_now && row.amount.value > 0 && (
                        <span className="block text-2xs text-muted-foreground">
                          وارد صندوق نمی‌شود
                        </span>
                      )}
                    </td>
                    <td className="p-3">
                      <Num value={row.count} variant="table" />
                    </td>
                    <td className="p-3 text-end">
                      <Money rial={row.amount.value} digits="latin" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        {report.accounts.length > 0 && (
          <section className="space-y-2">
            <h2 className="text-sm font-semibold">به تفکیک حساب</h2>
            <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
              {report.accounts.map((account) => (
                <div
                  key={account.account_id ?? account.name}
                  className="flex items-baseline justify-between"
                >
                  <dt className="text-muted-foreground">{account.name}</dt>
                  <dd>
                    <Money rial={account.amount.value} digits="latin" />
                  </dd>
                </div>
              ))}
            </dl>
          </section>
        )}

        {report.profit && (
          <section className="space-y-2">
            <h2 className="text-sm font-semibold">سود امروز</h2>
            <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
              <Row label="فروش (بدون مالیات)" money={report.profit.revenue} />
              <Row label="بهای تمام‌شده" money={report.profit.cost} />
              {report.profit.returned_revenue.value > 0 && (
                <Row label="کسر بابت برگشت" money={report.profit.returned_revenue} />
              )}
              <div className="flex items-baseline justify-between border-t border-border pt-2 font-semibold">
                <dt>سود</dt>
                <dd data-testid="day-profit">
                  <Money rial={report.profit.profit.value} digits="latin" withUnit signed />
                </dd>
              </div>
              <div className="flex items-baseline justify-between text-2xs text-muted-foreground">
                <dt>حاشیه سود</dt>
                <dd>
                  <Num value={report.profit.margin_percent} variant="table" />٪
                </dd>
              </div>
            </dl>
          </section>
        )}
      </div>
    </AppShell>
  );
}

function Row({ label, money }: { label: string; money: MoneyValue }) {
  return (
    <div className="flex items-baseline justify-between">
      <dt className="text-muted-foreground">{label}</dt>
      <dd>
        <Money rial={money.value} digits="latin" />
      </dd>
    </div>
  );
}
