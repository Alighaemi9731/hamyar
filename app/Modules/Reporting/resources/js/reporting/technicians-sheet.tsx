import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';

import type { ReportPeriod, TechnicianRow } from './types';

export interface TechniciansSheetProps {
  period: ReportPeriod;
  rows: TechnicianRow[];
  showsCost: boolean;
}

/**
 * کارکرد تکنسین‌ها, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function TechniciansSheet({ period, rows, showsCost }: TechniciansSheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">کارکرد تکنسین‌ها</h2>
        <p className="mt-1 text-sm text-black/60">
          از {period.from_jalali} تا {period.to_jalali}
        </p>
      </header>

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">
          در این بازه دستگاهی تحویل نشده است.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">تکنسین</th>
                <th className="py-2 text-end font-medium">تحویل‌شده</th>
                <th className="py-2 text-end font-medium">روی میز (امروز)</th>
                <th className="py-2 text-end font-medium">میانگین زمان</th>
                {showsCost ? <th className="py-2 text-end font-medium">هزینه قطعات</th> : null}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.technician} className="border-b last:border-0">
                  <td className="py-2">{row.technician}</td>
                  <td className="py-2 text-end">
                    <Num value={row.delivered} variant="table" />
                  </td>
                  <td className="py-2 text-end">
                    <Num value={row.open} variant="table" />
                  </td>
                  <td className="py-2 text-end">{turnaround(row.avg_turnaround_hours)}</td>
                  {showsCost ? (
                    <td className="py-2 text-end">
                      <Money rial={row.parts_cost?.value ?? 0} digits="latin" />
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        میانگین زمان از پذیرش تا تحویل است، شامل روزهایی که دستگاه منتظر قطعه یا تأیید مشتری بوده —
        چون مشتری همان انتظار را تجربه کرده است. ستون «روی میز» به بازه محدود نیست.
      </footer>
    </div>
  );
}

/**
 * «۲ روز و ۴ ساعت» — days and hours, because a repair is measured in both.
 *
 * A bare hour count reads fine at 6 and badly at 137, and the second is the number a
 * shop is actually trying to react to.
 */
export function turnaround(hours: number) {
  if (hours <= 0) {
    return <Num value={0} variant="table" />;
  }

  const days = Math.floor(hours / 24);
  const rest = hours % 24;

  if (days === 0) {
    return (
      <>
        <Num value={rest} variant="table" /> ساعت
      </>
    );
  }

  if (rest === 0) {
    return (
      <>
        <Num value={days} variant="table" /> روز
      </>
    );
  }

  return (
    <>
      <Num value={days} variant="table" /> روز و <Num value={rest} variant="table" /> ساعت
    </>
  );
}
