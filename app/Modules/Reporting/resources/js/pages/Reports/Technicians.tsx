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

interface Row {
  technician: string;
  delivered: number;
  open: number;
  avg_turnaround_hours: number;
  /** Absent for a viewer who may not see what the shop paid. */
  parts_cost?: MoneyValue;
}

interface Props {
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  shows_cost: boolean;
  can_export: boolean;
  rows: Row[];
}

/**
 * Technician performance — «چند دستگاه تحویل شد و چقدر طول کشید».
 *
 * ## Turnaround is rendered in days and hours, not in hours
 *
 * The server sends hours because that is the unit an average survives rounding in; a
 * shop reads «۲ روز و ۴ ساعت». Sending a pre-formatted string instead would put a
 * Persian sentence in the export, where a spreadsheet wants a number to sort on.
 *
 * ## "روی میز" is not filtered by the range, and says so
 *
 * Open work has no date to be inside — a ticket from two months ago that is still open
 * is open today. The column header carries the caveat rather than leaving the reader to
 * wonder why the two columns do not add up.
 */
export default function TechnicianReport({
  period,
  shows_cost: showsCost,
  can_export: canExport,
  rows,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);

  const query = () => ({
    from: from ? formatJalali(from, { persianDigits: false }) : '',
    to: to ? formatJalali(to, { persianDigits: false }) : '',
  });

  const apply = () => {
    router.get('/reporting/technicians', query(), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/technicians/export?${new URLSearchParams(query()).toString()}`;

  return (
    <AppShell title="کارکرد تکنسین‌ها">
      <Head title="کارکرد تکنسین‌ها" />

      <PrintLayout.A4
        toolbar={
          <div className="flex flex-wrap items-end gap-3">
            <Link
              href="/reporting"
              className="me-2 self-center text-sm text-primary hover:underline"
            >
              فهرست گزارش‌ها
            </Link>

            <div className="grid gap-1.5">
              <Label htmlFor="from">از تاریخ</Label>
              <JDatePicker id="from" value={from} onChange={setFrom} clearable={false} />
            </div>

            <div className="grid gap-1.5">
              <Label htmlFor="to">تا تاریخ</Label>
              <JDatePicker id="to" value={to} onChange={setTo} clearable={false} />
            </div>

            <Button variant="outline" onClick={apply}>
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
        }
      >
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
            میانگین زمان از پذیرش تا تحویل است، شامل روزهایی که دستگاه منتظر قطعه یا تأیید مشتری
            بوده — چون مشتری همان انتظار را تجربه کرده است. ستون «روی میز» به بازه محدود نیست.
          </footer>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}

/**
 * «۲ روز و ۴ ساعت» — days and hours, because a repair is measured in both.
 *
 * A bare hour count reads fine at 6 and badly at 137, and the second is the number a
 * shop is actually trying to react to.
 */
function turnaround(hours: number) {
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
