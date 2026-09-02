import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';

import type { OperationsRow, OperationsTotals, OperationsWallet, ReportPeriod } from './types';

export interface OperationsSheetProps {
  period: ReportPeriod;
  rows: OperationsRow[];
  totals: OperationsTotals;
  wallet: OperationsWallet;
}

/**
 * مصرف پیامک, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function OperationsSheet({ period, rows, totals, wallet }: OperationsSheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">مصرف پیامک</h2>
        <p className="mt-1 text-sm text-black/60">
          از {period.from_jalali} تا {period.to_jalali}
        </p>
      </header>

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="کل پیامک" count={totals.messages} />
        <Figure label="کل بخش" count={totals.segments} />
        <Figure label="هزینه بازه" value={totals.cost} />
        <Figure label="اعتبار فعلی کیف پول" value={wallet.balance} />
      </div>

      <div className="mb-6 grid gap-4 text-sm sm:grid-cols-3">
        <Figure label="شارژ در بازه" value={wallet.topups} />
        <Figure label="مصرف در بازه" value={wallet.charges} />
        <Figure
          label="ناموفق"
          count={totals.failed}
          tone={totals.failed > 0 ? 'danger' : undefined}
        />
      </div>

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">
          در این بازه پیامکی ارسال نشده است.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm tabular-nums">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">قالب</th>
                <th className="py-2 text-end font-medium">ارسال‌شده</th>
                <th className="py-2 text-end font-medium">ناموفق</th>
                <th className="py-2 text-end font-medium">مسدود</th>
                <th className="py-2 text-end font-medium">در صف</th>
                <th className="py-2 text-end font-medium">بخش</th>
                <th className="py-2 text-end font-medium">هزینه</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.label} className="border-b last:border-0">
                  <td className="py-2">{row.label}</td>
                  <td className="py-2 text-end text-success">
                    <Num value={row.sent} variant="table" />
                  </td>
                  <td className="py-2 text-end text-danger">
                    <Num value={row.failed} variant="table" />
                  </td>
                  <td className="py-2 text-end text-black/60">
                    <Num value={row.suppressed} variant="table" />
                  </td>
                  <td className="py-2 text-end text-black/60">
                    <Num value={row.queued} variant="table" />
                  </td>
                  <td className="py-2 text-end">
                    <Num value={row.segments} variant="table" />
                  </td>
                  <td className="py-2 text-end font-semibold">
                    <Money rial={row.cost.value} digits="latin" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        هزینه بر اساس «بخش» است نه تعداد پیامک: هر پیامک فارسی تا ۷۰ نویسه یک بخش حساب می‌شود، پس یک
        کلمهٔ اضافه در یک قالب، هزینهٔ همهٔ ارسال‌های آن را دو برابر می‌کند. «مسدود» یعنی گیرنده در
        فهرست انصراف بوده و پیامک عمداً ارسال نشده — این موفقیت است، نه خطا. اعتبار کیف پول مربوط به
        همین لحظه است، نه پایان بازه.
      </footer>
    </div>
  );
}

function Figure({
  label,
  value,
  count,
  tone,
}: {
  label: string;
  value?: MoneyValue;
  count?: number;
  tone?: 'danger';
}) {
  return (
    <div className="rounded-control border p-3">
      <p className="text-xs text-black/60">{label}</p>
      <p className={`mt-1 font-semibold ${tone === 'danger' ? 'text-danger' : ''}`}>
        {value ? (
          <bdi>
            <Money rial={value.value} withUnit digits="latin" />
          </bdi>
        ) : (
          <Num value={count ?? 0} variant="table" />
        )}
      </p>
    </div>
  );
}
