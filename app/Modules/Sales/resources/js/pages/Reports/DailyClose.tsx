import { Head, router } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import { useState } from 'react';

import { DataTable } from '@/components/domain/data-table';
import { Money } from '@/components/domain/money';
import { MoneyLadder, MoneyRow } from '@/components/domain/money-ladder';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
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
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

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
 *
 * ## It is a document, so it goes through `PrintLayout`
 *
 * It imported `printSheet` and never wrapped anything: Ctrl+P printed the app shell, the
 * filter row and the sidebar around a sheet with no `@page` rule, on whatever paper the
 * browser defaulted to. The Z report is the one piece of paper a shop staples to the
 * day's cash, and it went through none of the system that exists for exactly that.
 * `PrintLayout.A4` owns the paper now and the filters sit in its `no-print` toolbar.
 *
 * ## The money is in ladders, and the payments are a real table
 *
 * Four `flex justify-between` lists of figures — the same defect the treasury day-close
 * carried, where every row has its own right edge. And the payments table set `text-end`
 * on «مبلغ», which in RTL is the physical left: measured on a day with sales, its four
 * figures scattered across **65px**. `DataTable`'s `numeric` flag is the physical right.
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

  const toolbar = (
    <div className="flex flex-wrap items-end gap-3">
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
  );

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="گزارش Z"
          title="بستن صندوق"
          back={{ href: '/sales', label: 'فاکتورهای فروش' }}
          description={`${date} · ${branch?.name ?? 'همه شعبه‌ها'}`}
          actions={
            <Button type="button" variant="outline" onClick={printSheet}>
              <PrinterIcon aria-hidden />
              چاپ
            </Button>
          }
        />
      }
    >
      <Head title="گزارش Z" />

      <PrintLayout.A4 toolbar={toolbar}>
        <div className="p-8 print:p-0">
          <header className="mb-6 border-b pb-4">
            <h2 className="text-lg font-bold">گزارش Z — بستن صندوق</h2>
            <p className="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm text-black/60">
              <span className="tabular">{date}</span>
              <span>{branch?.name ?? 'همه شعبه‌ها'}</span>
              <span>
                <Num value={report.invoice_count} variant="prose" /> فاکتور
              </span>
              {report.void_count > 0 && (
                <span>
                  <Num value={report.void_count} variant="prose" /> ابطال
                </span>
              )}
              {report.return_count > 0 && (
                <span>
                  <Num value={report.return_count} variant="prose" /> برگشت
                </span>
              )}
            </p>
          </header>

          {/* The number somebody is counting notes against. */}
          <div className="mb-6 rounded-control border p-6">
            <p className="text-sm text-black/60">موجودی مورد انتظار صندوق</p>
            {/* The unit on its own line. Inline, «۷۱٬۹۹۸٬۰۰۰ تومان» at this size is 316px
                and pushed a 375px phone 14px sideways — the same escape the billing plan
                cards made, measured the same way. */}
            <p className="mt-1 text-3xl font-bold" data-testid="expected-cash">
              <Money
                rial={report.expected_cash.value}
                digits="latin"
                withUnit
                unitPlacement="block"
              />
            </p>
            <p className="mt-2 text-2xs text-black/60">
              دریافت نقدی امروز، منهای وجه نقدی که بابت برگشت از فروش به مشتری گذری پرداخت شده است.
              چک، نسیه و معاوضه وارد این عدد نمی‌شوند.
            </p>
          </div>

          <div className="grid gap-6 sm:grid-cols-2">
            <section className="space-y-2">
              <h3 className="text-sm font-semibold">فروش</h3>
              <div className="rounded-control border p-4 text-sm">
                <MoneyLadder>
                  <MoneyRow label="جمع کالاها" rial={report.gross.value} />
                  <MoneyRow label="تخفیف" rial={report.discount.value} />
                  <MoneyRow label="مالیات" rial={report.vat.value} />
                  <MoneyRow label="ارسال" rial={report.shipping.value} />
                  <MoneyRow label="گرد کردن" rial={report.rounding.value} />
                  <MoneyRow label="فروش خالص" rial={report.net.value} tone="text-black" divider />
                  {report.refunded.value > 0 && (
                    <MoneyRow label="برگشت نقدی" rial={report.refunded.value} />
                  )}
                  {report.credit_extended.value > 0 && (
                    <MoneyRow label="نسیه داده‌شده امروز" rial={report.credit_extended.value} />
                  )}
                </MoneyLadder>
                {/* The unit on its own line, never on a rung: it does not fit the ladder's
                    fixed value track. */}
                <p className="mt-2 text-2xs text-black/60" data-testid="net-sales">
                  <Money rial={report.net.value} digits="latin" withUnit unitPlacement="block" />
                </p>
              </div>
            </section>

            <section className="space-y-2">
              <h3 className="text-sm font-semibold">روش‌های پرداخت</h3>
              <DataTable
                caption="روش‌های پرداخت امروز، شامل آن‌هایی که وارد صندوق نمی‌شوند."
                rows={report.payments}
                rowKey={(row) => row.method}
                columns={[
                  {
                    key: 'label',
                    header: 'روش',
                    cell: (row) => (
                      <span className={cn(row.amount.value === 0 && 'text-black/60')}>
                        {row.label}
                        {!row.settles_now && row.amount.value > 0 && (
                          <span className="block text-2xs text-black/60">وارد صندوق نمی‌شود</span>
                        )}
                      </span>
                    ),
                  },
                  {
                    key: 'count',
                    header: 'تعداد',
                    numeric: true,
                    cell: (row) => <Num value={row.count} />,
                  },
                  {
                    key: 'amount',
                    header: 'مبلغ',
                    numeric: true,
                    cell: (row) => <Money rial={row.amount.value} digits="latin" />,
                  },
                ]}
              />
            </section>

            {report.accounts.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-sm font-semibold">به تفکیک حساب</h3>
                <div className="rounded-control border p-4 text-sm">
                  <MoneyLadder>
                    {report.accounts.map((account) => (
                      <MoneyRow
                        key={account.account_id ?? account.name}
                        label={account.name}
                        rial={account.amount.value}
                      />
                    ))}
                  </MoneyLadder>
                </div>
              </section>
            )}

            {report.profit && (
              <section className="space-y-2">
                <h3 className="text-sm font-semibold">سود امروز</h3>
                <div className="rounded-control border p-4 text-sm">
                  <MoneyLadder>
                    <MoneyRow label="فروش (بدون مالیات)" rial={report.profit.revenue.value} />
                    <MoneyRow label="بهای تمام‌شده" rial={report.profit.cost.value} />
                    {report.profit.returned_revenue.value > 0 && (
                      <MoneyRow
                        label="کسر بابت برگشت"
                        rial={report.profit.returned_revenue.value}
                      />
                    )}
                    <MoneyRow
                      label="سود"
                      rial={report.profit.profit.value}
                      tone="text-black"
                      signed
                      divider
                    />
                  </MoneyLadder>
                  <p className="mt-2 text-2xs text-black/60" data-testid="day-profit">
                    <Money
                      rial={report.profit.profit.value}
                      digits="latin"
                      withUnit
                      unitPlacement="block"
                      signed
                    />
                    {' · '}حاشیه سود <Num value={report.profit.margin_percent} variant="prose" />٪
                  </p>
                </div>
              </section>
            )}
          </div>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}
