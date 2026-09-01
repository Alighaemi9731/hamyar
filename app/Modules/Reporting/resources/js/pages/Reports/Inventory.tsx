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

type Cut = 'valuation' | 'dead';

interface Row {
  label: string;
  kind: 'standard' | 'serialized';
  quantity: number;
  value: MoneyValue;
  unit_cost?: MoneyValue;
  idle_days?: number;
  last_out?: string;
}

interface Props {
  cut: Cut;
  as_of: string;
  as_of_jalali: string;
  days: number;
  day_options: number[];
  can_export: boolean;
  totals: {
    value: MoneyValue;
    device_value: MoneyValue;
    devices: number;
    items: number;
    lines: number;
  };
  rows: Row[];
}

const CUTS: { key: Cut; label: string }[] = [
  { key: 'valuation', label: 'ارزش موجودی' },
  { key: 'dead', label: 'کالای راکد' },
];

/**
 * Stock valuation and dead stock.
 *
 * ## A date, not a range
 *
 * «موجودی» is a figure at an instant, so the filter is one as-of date rather than the
 * range every other report takes. Dead stock swaps it for a threshold in days, because
 * that is how the question is phrased at the counter.
 *
 * ## Devices and items are counted apart
 *
 * A mobile shop's two kinds of stock are two kinds of asset — handsets tracked one by one
 * and accessories tracked by quantity — and the accountant asks for them separately. A
 * single «ارزش کل» would let forty handsets and no accessories read the same as neither.
 */
export default function InventoryReport({
  cut,
  as_of: asOf,
  as_of_jalali: asOfJalali,
  days,
  day_options: dayOptions,
  can_export: canExport,
  totals,
  rows,
}: Props) {
  const [date, setDate] = useState<string | null>(asOf);

  const dead = cut === 'dead';

  const query = (next: Partial<{ cut: Cut; as_of: string | null; days: number }> = {}) => {
    const merged = { cut, as_of: date, days, ...next };

    return {
      cut: merged.cut,
      as_of: merged.as_of ? formatJalali(merged.as_of, { persianDigits: false }) : '',
      days: String(merged.days),
    };
  };

  const apply = (next: Partial<{ cut: Cut; as_of: string | null; days: number }> = {}) => {
    router.get('/reporting/inventory', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/inventory/export?${new URLSearchParams(query()).toString()}`;

  return (
    <AppShell title={dead ? 'کالای راکد' : 'ارزش موجودی انبار'}>
      <Head title={dead ? 'کالای راکد' : 'ارزش موجودی انبار'} />

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
                  onClick={() => apply({ cut: entry.key })}
                >
                  {entry.label}
                </Button>
              ))}
            </div>

            <div className="flex flex-wrap items-end gap-3">
              {dead ? (
                <div className="grid gap-1.5">
                  <Label htmlFor="days">بدون خروج به مدت</Label>
                  <div id="days" className="flex flex-wrap gap-2">
                    {dayOptions.map((option) => (
                      <Button
                        key={option}
                        variant={option === days ? 'default' : 'outline'}
                        onClick={() => apply({ days: option })}
                      >
                        <Num value={option} variant="table" /> روز
                      </Button>
                    ))}
                  </div>
                </div>
              ) : (
                <>
                  <div className="grid gap-1.5">
                    <Label htmlFor="as_of">در تاریخ</Label>
                    <JDatePicker id="as_of" value={date} onChange={setDate} clearable={false} />
                  </div>

                  <Button variant="outline" onClick={() => apply()}>
                    اعمال تاریخ
                  </Button>
                </>
              )}

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
            {/* The document's heading, not the page's — `AppShell` already renders an
                `<h1>` above the paper, and this repeated it, so every report shipped
                two page headings. On paper the outline does not exist and the
                rendering is unchanged; on screen a reader now gets one. */}
            <h2 className="text-lg font-bold">{dead ? 'کالای راکد' : 'ارزش موجودی انبار'}</h2>
            <p className="mt-1 text-sm text-black/60">
              {dead ? (
                <>
                  بدون خروج به مدت <Num value={days} variant="table" /> روز یا بیشتر
                </>
              ) : (
                <>در تاریخ {asOfJalali}</>
              )}
            </p>
          </header>

          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Figure label="ارزش کل" value={totals.value} />
            <Figure label="ارزش دستگاه‌ها" value={totals.device_value} />
            <Figure label="تعداد دستگاه" count={totals.devices} />
            <Figure label="تعداد اقلام" count={totals.items} />
          </div>

          {rows.length === 0 ? (
            <p className="py-12 text-center text-sm text-black/60">
              {dead ? 'کالای راکدی در این بازه وجود ندارد.' : 'موجودی‌ای ثبت نشده است.'}
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-black/60">
                    <th className="py-2 text-start font-medium">عنوان</th>
                    <th className="py-2 text-start font-medium">نوع</th>
                    <th className="py-2 text-end font-medium">تعداد</th>
                    {dead ? (
                      <>
                        <th className="py-2 text-end font-medium">روز بی‌حرکت</th>
                        <th className="py-2 text-end font-medium">آخرین خروج</th>
                      </>
                    ) : (
                      <th className="py-2 text-end font-medium">بهای واحد</th>
                    )}
                    <th className="py-2 text-end font-medium">ارزش</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, index) => (
                    <tr key={`${row.label}-${index}`} className="border-b last:border-0">
                      <td className="py-2">{row.label}</td>
                      <td className="py-2">{row.kind === 'serialized' ? 'دستگاه' : 'کالا'}</td>
                      <td className="py-2 text-end">
                        <Num value={row.quantity} variant="table" />
                      </td>
                      {dead ? (
                        <>
                          <td className="py-2 text-end">
                            <Num value={row.idle_days ?? 0} variant="table" />
                          </td>
                          <td className="py-2 text-end">{row.last_out}</td>
                        </>
                      ) : (
                        <td className="py-2 text-end">
                          <Money rial={row.unit_cost?.value ?? 0} digits="latin" />
                        </td>
                      )}
                      <td className="py-2 text-end">
                        <Money rial={row.value.value} digits="latin" />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <footer className="mt-6 border-t pt-3 text-xs text-black/60">
            ارزش‌ها به بهای تمام‌شده است، نه قیمت فروش. کالاهای معمولی به میانگین وزنی خرید و
            دستگاه‌های سریالی به بهای همان دستگاه ارزش‌گذاری می‌شوند.
          </footer>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}

function Figure({ label, value, count }: { label: string; value?: MoneyValue; count?: number }) {
  return (
    <div className="rounded-control border p-3">
      <p className="text-xs text-black/60">{label}</p>
      <p className="mt-1 font-semibold">
        {value ? (
          <Money rial={value.value} withUnit digits="latin" />
        ) : (
          <Num value={count ?? 0} variant="table" />
        )}
      </p>
    </div>
  );
}
