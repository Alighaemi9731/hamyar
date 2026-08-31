import { Head, Link, router } from '@inertiajs/react';
import { DownloadIcon, PrinterIcon } from 'lucide-react';
import { useState } from 'react';

import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { ReportPresets, type ReportPreset } from '@/components/domain/report-presets';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

type Cut = 'monthly' | 'rate';

interface Row {
  label: string;
  taxable_base: MoneyValue;
  vat: MoneyValue;
  /** Monthly cut only. */
  invoices?: number;
  exempt_base?: MoneyValue;
  rounding?: MoneyValue;
  /** Rate cut only. */
  rate?: number;
  lines?: number;
}

interface Props {
  cut: Cut;
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: Row[];
  totals: {
    taxable_base: MoneyValue;
    exempt_base: MoneyValue;
    vat: MoneyValue;
    rounding: MoneyValue;
    rows: number;
  };
}

const CUTS: { key: Cut; label: string }[] = [
  { key: 'monthly', label: 'ماهانه' },
  { key: 'rate', label: 'بر اساس نرخ' },
];

/**
 * خلاصه مالیات بر ارزش افزوده — the figures a VAT return is filled in from.
 *
 * ## Months are Jalali months
 *
 * The rows are folded server-side by `Jalali::monthKey()`. A return filed against «مرداد»
 * that quietly contains a week of Tir is a wrong filing, and Postgres has no Jalali
 * calendar to group by.
 *
 * ## The rounding column is not decoration
 *
 * ADR 0009 rule 3: an invoice's total is base + VAT − discount + shipping + rounding, and
 * a summary that hides the last term cannot be tied back to the invoices it summarises.
 * It is usually a rounding artefact and it is always shown, at any amount.
 */
export default function TaxReport({
  cut,
  period,
  can_export: canExport,
  report_key: reportKey,
  presets,
  rows,
  totals,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);

  const byRate = cut === 'rate';

  const query = (next: Partial<{ cut: Cut; from: string | null; to: string | null }> = {}) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Parameters<typeof query>[0] = {}) => {
    router.get('/reporting/tax', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/tax/export?${new URLSearchParams(query()).toString()}`;

  return (
    <AppShell title="خلاصه مالیات بر ارزش افزوده">
      <Head title="خلاصه مالیات بر ارزش افزوده" />

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

            <ReportPresets
              reportKey={reportKey}
              presets={presets}
              current={query()}
              path="/reporting/tax"
            />
          </div>
        }
      >
        <div className="p-8 print:p-0">
          <header className="mb-6 border-b pb-4">
            <h1 className="text-lg font-bold">خلاصه مالیات بر ارزش افزوده</h1>
            <p className="mt-1 text-sm text-black/60">
              از {period.from_jalali} تا {period.to_jalali}
            </p>
          </header>

          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Figure label="مأخذ مشمول" value={totals.taxable_base} />
            <Figure label="فروش معاف / نرخ صفر" value={totals.exempt_base} />
            <Figure label="مالیات" value={totals.vat} />
            <Figure label="گرد کردن" value={totals.rounding} />
          </div>

          {rows.length === 0 ? (
            <p className="py-12 text-center text-sm text-black/60">
              در این بازه فاکتور نهایی‌ای صادر نشده است.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm tabular-nums">
                <thead>
                  <tr className="border-b text-black/60">
                    <th className="py-2 text-start font-medium">{byRate ? 'نرخ' : 'ماه'}</th>
                    <th className="py-2 text-end font-medium">
                      {byRate ? 'تعداد سطر' : 'تعداد فاکتور'}
                    </th>
                    <th className="py-2 text-end font-medium">مأخذ مشمول</th>
                    {byRate ? null : <th className="py-2 text-end font-medium">معاف</th>}
                    <th className="py-2 text-end font-medium">مالیات</th>
                    {byRate ? null : <th className="py-2 text-end font-medium">گرد کردن</th>}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.label} className="border-b last:border-0">
                      <td className="py-2">{row.label}</td>
                      <td className="py-2 text-end">
                        <Num value={(byRate ? row.lines : row.invoices) ?? 0} variant="table" />
                      </td>
                      <td className="py-2 text-end">
                        <Money rial={row.taxable_base.value} digits="latin" />
                      </td>
                      {byRate ? null : (
                        <td className="py-2 text-end text-black/60">
                          <Money rial={row.exempt_base?.value ?? 0} digits="latin" />
                        </td>
                      )}
                      <td className="py-2 text-end font-semibold">
                        <Money rial={row.vat.value} digits="latin" />
                      </td>
                      {byRate ? null : (
                        <td className="py-2 text-end text-black/60">
                          <bdi>
                            <Money rial={row.rounding?.value ?? 0} digits="latin" />
                          </bdi>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <footer className="mt-6 border-t pt-3 text-xs text-black/60">
            ارقام از خود فاکتورها خوانده می‌شود، نه از نرخ امروز روی جمع فروش: مالیات هر سطر هنگام
            نهایی شدن فاکتور محاسبه و ذخیره شده و همان عدد اینجا جمع می‌شود. فاکتورهای ابطال‌شده
            شمرده نمی‌شوند، هرچند شمارهٔ آن‌ها محفوظ می‌ماند.
          </footer>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}

function Figure({ label, value }: { label: string; value: MoneyValue }) {
  return (
    <div className="rounded-control border p-3">
      <p className="text-xs text-black/60">{label}</p>
      <p className="mt-1 font-semibold">
        <bdi>
          <Money rial={value.value} withUnit digits="latin" />
        </bdi>
      </p>
    </div>
  );
}
