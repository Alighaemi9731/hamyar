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
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

type Cut = 'daily' | 'monthly' | 'product' | 'brand' | 'salesperson';

interface Row {
  label: string;
  count: number;
  revenue: MoneyValue;
  /** Absent for a viewer who may not see what the shop paid. */
  cost?: MoneyValue;
  margin?: MoneyValue;
}

interface Props {
  cut: Cut;
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  shows_margin: boolean;
  can_export: boolean;
  summary: {
    revenue: MoneyValue;
    invoice_count: number;
    returned_revenue: MoneyValue;
    cost?: MoneyValue;
    profit?: MoneyValue;
    margin_percent?: number;
  };
  rows: Row[];
}

const CUTS: { key: Cut; label: string; unit: string; heading: string }[] = [
  { key: 'daily', label: 'روزانه', unit: 'تعداد فاکتور', heading: 'تاریخ' },
  { key: 'monthly', label: 'ماهانه', unit: 'تعداد فاکتور', heading: 'ماه' },
  { key: 'product', label: 'بر اساس کالا', unit: 'تعداد', heading: 'کالا' },
  { key: 'brand', label: 'بر اساس برند', unit: 'تعداد', heading: 'برند' },
  { key: 'salesperson', label: 'بر اساس فروشنده', unit: 'تعداد', heading: 'فروشنده' },
];

/**
 * The sales report.
 *
 * ## The sheet on screen is the sheet that prints
 *
 * `PrintLayout.A4` owns the paper; the filter bar sits in its toolbar and carries
 * `no-print`, so Ctrl+P produces exactly what is being read — no preview route to drift
 * from it (design-system rule 9).
 *
 * ## The range survives a change of cut
 *
 * Switching from «روزانه» to «بر اساس کالا» keeps the dates. They are the same question
 * grouped differently, and re-typing a range to look at it another way is the friction
 * that stops people looking.
 *
 * ## Cost columns are absent, not blank
 *
 * A viewer without margin permission gets a four-column table, not a six-column one
 * with two empty columns — empty columns read as "no data" rather than "not for you".
 */
export default function SalesReport({
  cut,
  period,
  shows_margin: showsMargin,
  can_export: canExport,
  summary,
  rows,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);

  const active = CUTS.find((entry) => entry.key === cut);
  const unit = active?.unit ?? 'تعداد';

  const query = (next: Partial<{ cut: Cut; from: string | null; to: string | null }>) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      // Jalali strings on the wire, Latin digits: `ReportPeriod::fromJalali` accepts
      // either, and the URL is something a shopkeeper copies into a chat message.
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Partial<{ cut: Cut; from: string | null; to: string | null }> = {}) => {
    router.get('/reporting/sales', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/sales/export?${new URLSearchParams(query({})).toString()}`;

  return (
    <AppShell title="گزارش فروش">
      <Head title="گزارش فروش" />

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
                  {/* A real navigation, not an Inertia visit: the response is a file. */}
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
            <h1 className="text-lg font-bold">گزارش فروش</h1>
            <p className="mt-1 text-sm text-black/60">
              {active?.label} · از {period.from_jalali} تا {period.to_jalali}
            </p>
          </header>

          {/*
            Returns are their own figure, never netted into the sales line: «چقدر
            فروختیم» and «چقدر برگشت خورد» are two questions, and one net number
            answers neither (SalesReports makes the same argument).
          */}
          <div className={cn('mb-6 grid gap-4 sm:grid-cols-3', showsMargin && 'lg:grid-cols-5')}>
            <Figure label="فروش خالص" value={summary.revenue} />
            <Figure label="تعداد فاکتور" count={summary.invoice_count} />
            <Figure label="برگشت از فروش" value={summary.returned_revenue} />
            {summary.profit ? <Figure label="سود ناخالص" value={summary.profit} /> : null}
            {summary.margin_percent === undefined ? null : (
              <Figure label="حاشیه سود" count={summary.margin_percent} suffix="٪" />
            )}
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
                    <th className="py-2 text-end font-medium">{unit}</th>
                    <th className="py-2 text-end font-medium">فروش</th>
                    {showsMargin ? (
                      <>
                        <th className="py-2 text-end font-medium">بهای تمام‌شده</th>
                        <th className="py-2 text-end font-medium">سود</th>
                      </>
                    ) : null}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.label} className="border-b last:border-0">
                      <td className="py-2">{row.label}</td>
                      <td className="py-2 text-end">
                        <Num value={row.count} variant="table" />
                      </td>
                      <td className="py-2 text-end">
                        <Money rial={row.revenue.value} digits="latin" />
                      </td>
                      {showsMargin ? (
                        <>
                          <td className="py-2 text-end">
                            <Money rial={row.cost?.value ?? 0} digits="latin" />
                          </td>
                          <td className="py-2 text-end">
                            <Money rial={row.margin?.value ?? 0} digits="latin" signed />
                          </td>
                        </>
                      ) : null}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Nothing on the sheet that is not on the paper. A `no-print` link inside
              the document is chrome that wandered in; it lives in the toolbar. */}
          <footer className="mt-6 border-t pt-3 text-xs text-black/60">
            ارقام بدون مالیات بر ارزش افزوده است.
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
