import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';

import type { ProfitCut, ProfitRow, ProfitSummary, ReportPeriod } from './types';

export interface ProfitSheetProps {
  cut: { key: ProfitCut; label: string; heading: string } | undefined;
  perUnit: boolean;
  period: ReportPeriod;
  summary: ProfitSummary;
  rows: ProfitRow[];
}

/**
 * گزارش سود, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function ProfitSheet({ cut: active, perUnit, period, summary, rows }: ProfitSheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">گزارش سود</h2>
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
        <p className="py-12 text-center text-sm text-black/60">در این بازه فروشی ثبت نشده است.</p>
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
                <tr key={`${row.label}-${row.invoice}-${index}`} className="border-b last:border-0">
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
        ارقام بدون مالیات بر ارزش افزوده است. بهای تمام‌شده همان مبلغی است که در لحظه فروش ثبت شده،
        نه قیمت امروز.
      </footer>
    </div>
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
