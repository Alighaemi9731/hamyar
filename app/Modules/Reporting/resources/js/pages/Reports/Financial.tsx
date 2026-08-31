import { Head, Link, router } from '@inertiajs/react';
import { DownloadIcon, PrinterIcon } from 'lucide-react';
import { useState } from 'react';

import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { ReportPresets, type ReportPreset } from '@/components/domain/report-presets';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

type Cut = 'aging' | 'cheques' | 'installments';
type Direction = 'receivable' | 'payable';

interface AgingRow {
  party_id: number;
  name: string;
  kind: string;
  total: MoneyValue;
  current: MoneyValue;
  days_60: MoneyValue;
  days_90: MoneyValue;
  older: MoneyValue;
  credit: MoneyValue;
}

interface ChequeRow {
  due_date: string;
  incoming: MoneyValue;
  incoming_count: number;
  outgoing: MoneyValue;
  outgoing_count: number;
  net: MoneyValue;
  cleared: MoneyValue;
  bounced: MoneyValue;
}

interface InstallmentRow {
  plan_number: string;
  party: string;
  sequence: number;
  due_at: string;
  amount: MoneyValue;
  collected: MoneyValue;
  outstanding: MoneyValue;
  status: string;
  overdue_days: number;
}

interface Props {
  cut: Cut;
  cuts: { key: Cut; label: string }[];
  direction: Direction;
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  as_of: string;
  as_of_jalali: string;
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: AgingRow[] | ChequeRow[] | InstallmentRow[];
  totals: Record<string, MoneyValue | number>;
  overdue?: {
    incoming: MoneyValue;
    incoming_count: number;
    outgoing: MoneyValue;
    outgoing_count: number;
  };
}

const TITLES: Record<Cut, string> = {
  aging: 'مانده حساب طرف‌ها',
  cheques: 'تقویم چک‌ها',
  installments: 'دفتر اقساط',
};

/**
 * The money side: who owes, what falls due, and which instalments were missed.
 *
 * ## The tabs are what this viewer may open, not all three
 *
 * `cuts` arrives already filtered by the server. Rendering all three and letting the
 * unavailable ones 403 would teach a Cashier that two-thirds of the screen is broken; the
 * honest version is that the shop has not given them that report.
 *
 * ## Aging takes a date, the other two take a range
 *
 * A balance is a figure at an instant — there is no balance "between Mordad and
 * Shahrivar" — so the filter bar swaps rather than showing a range the report ignores.
 *
 * ## Negative money is wrapped in `<bdi>`
 *
 * The net column goes negative on any day the shop pays out more than it takes in, and a
 * minus sign is bidi-neutral: without isolation it jumps to the far side of the number and
 * «−۲٬۰۰۰٬۰۰۰» renders as «۲٬۰۰۰٬۰۰۰−». `<Money/>` handles its own, and the ones built
 * here are wrapped at the call site.
 */
export default function FinancialReport({
  cut,
  cuts,
  direction,
  period,
  as_of: asOf,
  as_of_jalali: asOfJalali,
  can_export: canExport,
  report_key: reportKey,
  presets,
  rows,
  totals,
  overdue,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);
  const [date, setDate] = useState<string | null>(asOf);

  const isAging = cut === 'aging';

  const query = (
    next: Partial<{
      cut: Cut;
      direction: Direction;
      from: string | null;
      to: string | null;
      as_of: string | null;
    }> = {}
  ) => {
    const merged = { cut, direction, from, to, as_of: date, ...next };

    return {
      cut: merged.cut,
      direction: merged.direction,
      // Latin-digit Jalali on the wire: `ReportPeriod::fromJalali` accepts either, and the
      // URL is something a shopkeeper copies into a chat message.
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
      as_of: merged.as_of ? formatJalali(merged.as_of, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Parameters<typeof query>[0] = {}) => {
    router.get('/reporting/financial', query(next), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const exportHref = `/reporting/financial/export?${new URLSearchParams(query()).toString()}`;
  const title = TITLES[cut];

  return (
    <AppShell title={title}>
      <Head title={title} />

      <PrintLayout.A4
        toolbar={
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Link href="/reporting" className="me-2 text-sm text-primary hover:underline">
                فهرست گزارش‌ها
              </Link>

              {cuts.map((entry) => (
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
              {isAging ? (
                <>
                  <div className="grid gap-1.5">
                    <Label htmlFor="as_of">در تاریخ</Label>
                    <JDatePicker id="as_of" value={date} onChange={setDate} clearable={false} />
                  </div>

                  <div className="grid gap-1.5">
                    <Label htmlFor="direction">نوع مانده</Label>
                    <div id="direction" className="flex gap-2">
                      <Button
                        variant={direction === 'receivable' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => apply({ direction: 'receivable' })}
                      >
                        طلب از مشتری
                      </Button>
                      <Button
                        variant={direction === 'payable' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => apply({ direction: 'payable' })}
                      >
                        بدهی به تأمین‌کننده
                      </Button>
                    </div>
                  </div>
                </>
              ) : (
                <>
                  <div className="grid gap-1.5">
                    <Label htmlFor="from">از تاریخ</Label>
                    <JDatePicker id="from" value={from} onChange={setFrom} clearable={false} />
                  </div>

                  <div className="grid gap-1.5">
                    <Label htmlFor="to">تا تاریخ</Label>
                    <JDatePicker id="to" value={to} onChange={setTo} clearable={false} />
                  </div>
                </>
              )}

              <Button variant="outline" onClick={() => apply()}>
                اعمال
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
              path="/reporting/financial"
            />
          </div>
        }
      >
        <div className="p-8 print:p-0">
          <header className="mb-6 border-b pb-4">
            <h1 className="text-lg font-bold">{title}</h1>
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
      </PrintLayout.A4>
    </AppShell>
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
  overdue?: Props['overdue'];
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
