import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';

import type { ReportPeriod, TaxRow, TaxTotals } from './types';

export interface TaxSheetProps {
  byRate: boolean;
  period: ReportPeriod;
  rows: TaxRow[];
  totals: TaxTotals;
}

/**
 * خلاصه مالیات بر ارزش افزوده, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function TaxSheet({ byRate, period, rows, totals }: TaxSheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">خلاصه مالیات بر ارزش افزوده</h2>
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
        ارقام از خود فاکتورها خوانده می‌شود، نه از نرخ امروز روی جمع فروش: مالیات هر سطر هنگام نهایی
        شدن فاکتور محاسبه و ذخیره شده و همان عدد اینجا جمع می‌شود. فاکتورهای ابطال‌شده شمرده
        نمی‌شوند، هرچند شمارهٔ آن‌ها محفوظ می‌ماند.
      </footer>
    </div>
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
