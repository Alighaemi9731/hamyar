import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';
import { StatusBadge } from '@/components/domain/status-badge';

import type {
  AgingRow,
  ChequeOverdue,
  ChequeRow,
  FinancialCut,
  FinancialDirection,
  InstallmentRow,
  ReportPeriod,
} from './types';

export interface FinancialSheetProps {
  cut: FinancialCut;
  title: string;
  isAging: boolean;
  direction: FinancialDirection;
  asOfJalali: string;
  period: ReportPeriod;
  rows: AgingRow[] | ChequeRow[] | InstallmentRow[];
  totals: Record<string, MoneyValue | number>;
  overdue?: ChequeOverdue;
}

/**
 * مانده حساب، تقویم چک و دفتر اقساط, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function FinancialSheet({
  cut,
  title,
  isAging,
  direction,
  asOfJalali,
  period,
  rows,
  totals,
  overdue,
}: FinancialSheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">{title}</h2>
        <p className="mt-1 text-sm text-black/60">
          {isAging ? (
            <>
              در تاریخ {asOfJalali} —{' '}
              {direction === 'receivable' ? 'طلب از مشتریان' : 'بدهی به تأمین‌کنندگان'}
            </>
          ) : (
            <>
              از {period.from_jalali} تا {period.to_jalali}
            </>
          )}
        </p>
      </header>

      {cut === 'aging' ? (
        <AgingTable rows={rows as AgingRow[]} totals={totals} />
      ) : cut === 'cheques' ? (
        <ChequeTable rows={rows as ChequeRow[]} totals={totals} overdue={overdue} />
      ) : (
        <InstallmentTable rows={rows as InstallmentRow[]} totals={totals} />
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function AgingTable({
  rows,
  totals,
}: {
  rows: AgingRow[];
  totals: Record<string, MoneyValue | number>;
}) {
  return (
    <>
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Figure label="کل مانده" value={totals.total as MoneyValue} />
        <Figure label="جاری (تا ۳۰ روز)" value={totals.current as MoneyValue} />
        <Figure label="۳۱ تا ۶۰ روز" value={totals.days_60 as MoneyValue} />
        <Figure label="۶۱ تا ۹۰ روز" value={totals.days_90 as MoneyValue} />
        <Figure label="بیش از ۹۰ روز" value={totals.older as MoneyValue} tone="danger" />
      </div>

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">
          هیچ طرف حسابی با مانده باز وجود ندارد.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm tabular-nums">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">طرف حساب</th>
                <th className="py-2 text-end font-medium">کل</th>
                <th className="py-2 text-end font-medium">جاری</th>
                <th className="py-2 text-end font-medium">تا ۶۰ روز</th>
                <th className="py-2 text-end font-medium">تا ۹۰ روز</th>
                <th className="py-2 text-end font-medium">۹۰+</th>
                <th className="py-2 text-end font-medium">بستانکاری</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.party_id} className="border-b last:border-0">
                  <td className="py-2">{row.name}</td>
                  <td className="py-2 text-end font-semibold">
                    <Money rial={row.total.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end">
                    <Money rial={row.current.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end">
                    <Money rial={row.days_60.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end">
                    <Money rial={row.days_90.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end text-danger">
                    <Money rial={row.older.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end text-black/60">
                    <Money rial={row.credit.value} digits="latin" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        سن بدهی با فرض «هر پرداخت، قدیمی‌ترین بدهی را تسویه می‌کند» محاسبه می‌شود. ستون بستانکاری،
        پیش‌پرداختی است که هنوز به بدهی‌ای نخورده — از مانده کسر نشده تا دو رقم با هم قاطی نشوند.
      </footer>
    </>
  );
}

function ChequeTable({
  rows,
  totals,
  overdue,
}: {
  rows: ChequeRow[];
  totals: Record<string, MoneyValue | number>;
  overdue?: ChequeOverdue;
}) {
  return (
    <>
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="دریافتی بازه" value={totals.incoming as MoneyValue} />
        <Figure label="پرداختی بازه" value={totals.outgoing as MoneyValue} />
        <Figure label="خالص" value={totals.net as MoneyValue} />
        <Figure label="تعداد روز" count={totals.days as number} />
      </div>

      {overdue && (overdue.incoming_count > 0 || overdue.outgoing_count > 0) ? (
        <div className="mb-6 rounded-control border border-danger/25 bg-danger/5 p-3 text-sm">
          <p className="font-semibold text-danger">چک‌های سررسیدگذشته و باز</p>
          <p className="mt-1 text-black/70">
            <Num value={overdue.incoming_count} variant="table" /> فقره دریافتی به مبلغ{' '}
            <Money rial={overdue.incoming.value} digits="latin" /> و{' '}
            <Num value={overdue.outgoing_count} variant="table" /> فقره پرداختی به مبلغ{' '}
            <Money rial={overdue.outgoing.value} digits="latin" /> — بیرون از این بازه، چون یک چک
            سررسیدگذشته تاریخی در آینده ندارد که داخل بازه باشد.
          </p>
        </div>
      ) : null}

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">در این بازه چکی سررسید نمی‌شود.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm tabular-nums">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">سررسید</th>
                <th className="py-2 text-end font-medium">فقره دریافتی</th>
                <th className="py-2 text-end font-medium">دریافتی</th>
                <th className="py-2 text-end font-medium">فقره پرداختی</th>
                <th className="py-2 text-end font-medium">پرداختی</th>
                <th className="py-2 text-end font-medium">خالص</th>
                <th className="py-2 text-end font-medium">وصول‌شده</th>
                <th className="py-2 text-end font-medium">برگشتی</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.due_date} className="border-b last:border-0">
                  <td className="py-2">{row.due_date}</td>
                  <td className="py-2 text-end">
                    <Num value={row.incoming_count} variant="table" />
                  </td>
                  <td className="py-2 text-end text-success">
                    <Money rial={row.incoming.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end">
                    <Num value={row.outgoing_count} variant="table" />
                  </td>
                  <td className="py-2 text-end">
                    <Money rial={row.outgoing.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end font-semibold">
                    <bdi>
                      <Money rial={row.net.value} digits="latin" />
                    </bdi>
                  </td>
                  <td className="py-2 text-end text-black/60">
                    <Money rial={row.cleared.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end text-danger">
                    <Money rial={row.bounced.value} digits="latin" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        فقط چک‌های باز در خالص هر روز حساب می‌شوند. چکی که وصول شده پولش رسیده و دوباره شمردنش یعنی
        نقدینگی‌ای که وجود ندارد؛ چک برگشتی هم جداگانه آمده چون کار دارد، نه اینکه از تقویم حذف شود.
      </footer>
    </>
  );
}

function InstallmentTable({
  rows,
  totals,
}: {
  rows: InstallmentRow[];
  totals: Record<string, MoneyValue | number>;
}) {
  return (
    <>
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="سررسید بازه" value={totals.due as MoneyValue} />
        <Figure label="وصول‌شده" value={totals.collected as MoneyValue} />
        <Figure label="مانده" value={totals.outstanding as MoneyValue} />
        <Figure label="معوق" value={totals.overdue as MoneyValue} tone="danger" />
      </div>

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">در این بازه قسطی سررسید نمی‌شود.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm tabular-nums">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">قرارداد</th>
                <th className="py-2 text-start font-medium">طرف حساب</th>
                <th className="py-2 text-end font-medium">قسط</th>
                <th className="py-2 text-end font-medium">سررسید</th>
                <th className="py-2 text-end font-medium">مبلغ</th>
                <th className="py-2 text-end font-medium">وصول‌شده</th>
                <th className="py-2 text-end font-medium">مانده</th>
                <th className="py-2 text-end font-medium">وضعیت</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={`${row.plan_number}-${row.sequence}`} className="border-b last:border-0">
                  <td className="py-2">{row.plan_number}</td>
                  <td className="py-2">{row.party}</td>
                  <td className="py-2 text-end">
                    <Num value={row.sequence} variant="table" />
                  </td>
                  <td className="py-2 text-end">{row.due_at}</td>
                  <td className="py-2 text-end">
                    <Money rial={row.amount.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end text-success">
                    <Money rial={row.collected.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end font-semibold">
                    <Money rial={row.outstanding.value} digits="latin" />
                  </td>
                  <td className="py-2 text-end">
                    <StatusBadge status={row.overdue_days > 0 ? 'overdue' : row.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        وصولی هر قسط جمع پرداخت‌های ثبت‌شده روی همان قسط است، منهای مبلغ اضافه‌ای که به‌عنوان
        بستانکاری روی حساب طرف نشسته. قسطی که تسویه شده معوق شمرده نمی‌شود، حتی اگر دیر پرداخت شده
        باشد.
      </footer>
    </>
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
