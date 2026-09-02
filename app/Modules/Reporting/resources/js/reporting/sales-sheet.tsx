import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

import type { CutDefinition, ReportPeriod, SalesRow, SalesSummary } from './types';

export interface SalesSheetProps {
  cut: CutDefinition | undefined;
  period: ReportPeriod;
  summary: SalesSummary;
  rows: SalesRow[];
  showsMargin: boolean;
}

/**
 * گزارش فروش, as a document.
 *
 * ## Moved here without a character changed
 *
 * This is the markup that has been printing since the report shipped, lifted out of the
 * page so the screen view can be rebuilt around it rather than on top of it. **Nothing in
 * this file may be adjusted for the screen.** It is measured in millimetres by a shop that
 * mails it to an accountant, and the moment it starts serving two audiences it stops being
 * reliable for either.
 *
 * The page decides when it is shown; this file decides only what is on the paper.
 *
 * ## Ink on white, and the classes say so
 *
 * `text-black/60` rather than `text-muted-foreground` is deliberate and looks like a
 * violation of the token rule. It is not: `PrintLayout` renders a `[data-paper]` light
 * island where the app's tokens are deliberately overridden, so a sheet reads the same in
 * dark mode as on the printer.
 */
export function SalesSheet({ cut, period, summary, rows, showsMargin }: SalesSheetProps) {
  const unit = cut?.unit ?? 'تعداد';

  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's. */}
        <h2 className="text-lg font-bold">گزارش فروش</h2>
        <p className="mt-1 text-sm text-black/60">
          {cut?.label} · از {period.from_jalali} تا {period.to_jalali}
        </p>
      </header>

      {/*
        Returns are their own figure, never netted into the sales line: «چقدر فروختیم» and
        «چقدر برگشت خورد» are two questions, and one net number answers neither
        (SalesReports makes the same argument).
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
        <p className="py-12 text-center text-sm text-black/60">در این بازه فروشی ثبت نشده است.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">{cut?.heading ?? 'عنوان'}</th>
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

      {/* Nothing on the sheet that is not on the paper. A `no-print` link inside the
          document is chrome that wandered in; it lives in the toolbar. */}
      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        ارقام بدون مالیات بر ارزش افزوده است.
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
