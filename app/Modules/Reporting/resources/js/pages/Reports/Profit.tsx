import { Head, Link, router } from '@inertiajs/react';
import { DownloadIcon, PrinterIcon } from 'lucide-react';
import { useState } from 'react';

import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

type Cut = 'product' | 'brand' | 'imei';

interface Row {
  label: string;
  count: number;
  product: string;
  invoice: string;
  sold_at: string;
  customer: string;
  revenue: MoneyValue;
  cost: MoneyValue;
  margin: MoneyValue;
}

interface Props {
  cut: Cut;
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  can_export: boolean;
  summary: {
    revenue: MoneyValue;
    cost: MoneyValue;
    profit: MoneyValue;
    margin_percent: number;
    invoice_count: number;
  };
  rows: Row[];
}

const CUTS: { key: Cut; label: string; heading: string }[] = [
  { key: 'product', label: 'بر اساس کالا', heading: 'کالا' },
  { key: 'brand', label: 'بر اساس برند', heading: 'برند' },
  { key: 'imei', label: 'هر دستگاه', heading: 'شناسه دستگاه' },
];

/**
 * «از چی سود کردیم» — the profit report.
 *
 * ## Rows are ordered by profit, and that is the whole point
 *
 * The sales report puts the best sellers first. This puts the best *earners* first, and
 * the two lists differ: a case sold two hundred times out-earns a phone that moved twice.
 * The ordering happens in SQL, before the limit, so a low-volume high-margin line cannot
 * be discarded before it is ranked.
 *
 * ## No permission branch on this page
 *
 * Unlike the sales report, there is nothing to hide — a viewer who may not see margin
 * never reaches this component, because the controller refuses the request and the report
 * index does not list it. Rendering a "maybe you can see cost" variant here would put the
 * decision in two places, and the second one always drifts.
 *
 * ## The IMEI stays Latin and LTR
 *
 * It is read down a phone line and typed into HAMTA (design-system rule 3), so it is
 * isolated with `dir="ltr"` rather than left to the bidi algorithm, which reorders a
 * fifteen-digit number sitting in a Persian sentence.
 */
export default function ProfitReport({ cut, period, can_export: canExport, summary, rows }: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);

  const active = CUTS.find((entry) => entry.key === cut);
  const perUnit = cut === 'imei';

  const query = (next: Partial<{ cut: Cut; from: string | null; to: string | null }>) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Partial<{ cut: Cut; from: string | null; to: string | null }> = {}) => {
    router.get('/reporting/profit', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/profit/export?${new URLSearchParams(query({})).toString()}`;

  return (
    <AppShell title="گزارش سود">
      <Head title="گزارش سود" />

      <PrintLayout.A4
        toolbar={
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Link href="/reporting" className="me-2 text-sm text-primary hover:underline">
                فهرست گزارش‌ها
              </Link>

              {CUTS.map((entry) => (
                <Button
                  key={entry.key}
                  variant={entry.key === cut ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => apply({ cut: entry.key })}
                >
                  {entry.label}
                </Button>
              ))}
            </div>

            <div className="flex flex-wrap items-end gap-3">
              <div className="grid gap-1.5">
                <Label htmlFor="from">از تاریخ</Label>
                <JDatePicker id="from" value={from} onChange={setFrom} clearable={false} />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="to">تا تاریخ</Label>
                <JDatePicker id="to" value={to} onChange={setTo} clearable={false} />
              </div>

              <Button variant="outline" onClick={() => apply()}>
                اعمال بازه
              </Button>

              <span className="grow" />

              <Button variant="outline" onClick={printSheet}>
                <PrinterIcon className="size-4" aria-hidden />
                چاپ
              </Button>

              {canExport ? (
                <Button asChild variant="outline">
                  <a href={exportHref}>
                    <DownloadIcon className="size-4" aria-hidden />
                    خروجی اکسل
                  </a>
                </Button>
              ) : null}
            </div>
          </div>
        }
      >
        <div className="p-8 print:p-0">
          <header className="mb-6 border-b pb-4">
            <h1 className="text-lg font-bold">گزارش سود</h1>
            <p className="mt-1 text-sm text-black/60">
              {active?.label} · از {period.from_jalali} تا {period.to_jalali}
            </p>
          </header>

          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Figure label="فروش خالص" value={summary.revenue} />
            <Figure label="بهای تمام‌شده" value={summary.cost} />
            <Figure label="سود ناخالص" value={summary.profit} />
            <Figure label="حاشیه سود" count={summary.margin_percent} suffix="٪" />
          </div>

          {rows.length === 0 ? (
            <p className="py-12 text-center text-sm text-black/60">
              در این بازه فروشی ثبت نشده است.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-black/60">
                    <th className="py-2 text-start font-medium">{active?.heading ?? 'عنوان'}</th>
                    {perUnit ? (
                      <>
                        <th className="py-2 text-start font-medium">کالا</th>
                        <th className="py-2 text-start font-medium">فاکتور</th>
                        <th className="py-2 text-start font-medium">تاریخ</th>
                      </>
                    ) : (
                      <th className="py-2 text-end font-medium">تعداد</th>
                    )}
                    <th className="py-2 text-end font-medium">فروش</th>
                    <th className="py-2 text-end font-medium">بهای تمام‌شده</th>
                    <th className="py-2 text-end font-medium">سود</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, index) => (
                    <tr
                      key={`${row.label}-${row.invoice}-${index}`}
                      className="border-b last:border-0"
                    >
                      <td className="py-2">
                        {perUnit ? (
                          <span className="ltr-value tabular" dir="ltr">
                            {row.label}
                          </span>
                        ) : (
                          row.label
                        )}
                      </td>

                      {perUnit ? (
                        <>
                          <td className="py-2">{row.product}</td>
                          <td className="py-2">{row.invoice}</td>
                          <td className="py-2">{row.sold_at}</td>
                        </>
                      ) : (
                        <td className="py-2 text-end">
                          <Num value={row.count} variant="table" />
                        </td>
                      )}

                      <td className="py-2 text-end">
                        <Money rial={row.revenue.value} digits="latin" />
                      </td>
                      <td className="py-2 text-end">
                        <Money rial={row.cost.value} digits="latin" />
                      </td>
                      <td className="py-2 text-end">
                        <Money rial={row.margin.value} digits="latin" signed />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <footer className="mt-6 border-t pt-3 text-xs text-black/60">
            ارقام بدون مالیات بر ارزش افزوده است. بهای تمام‌شده همان مبلغی است که در لحظه فروش ثبت
            شده، نه قیمت امروز.
          </footer>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}

function Figure({
  label,
  value,
  count,
  suffix,
}: {
  label: string;
  value?: MoneyValue;
  count?: number;
  suffix?: string;
}) {
  return (
    <div className="rounded-control border p-3">
      <p className="text-xs text-black/60">{label}</p>
      <p className="mt-1 font-semibold">
        {value ? (
          <Money rial={value.value} withUnit digits="latin" />
        ) : (
          <>
            <Num value={count ?? 0} variant="table" />
            {suffix}
          </>
        )}
      </p>
    </div>
  );
}
