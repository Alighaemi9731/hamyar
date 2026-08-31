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

interface Row {
  label: string;
  sent: number;
  failed: number;
  suppressed: number;
  queued: number;
  messages: number;
  segments: number;
  cost: MoneyValue;
}

interface Props {
  period: { from: string; to: string; from_jalali: string; to_jalali: string };
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: Row[];
  totals: {
    messages: number;
    segments: number;
    failed: number;
    cost: MoneyValue;
    templates: number;
  };
  wallet: {
    balance: MoneyValue;
    topups: MoneyValue;
    charges: MoneyValue;
    refunds: MoneyValue;
  };
}

/**
 * مصرف پیامک — per template, because that is the thing somebody can change.
 *
 * ## Segments, not messages
 *
 * A Persian SMS is 70 characters per segment against 160 for Latin, so a template that
 * grew by one polite word doubled the bill on everything it sends. The segment column is
 * where that shows up; the message count alone cannot say it.
 *
 * ## The wallet balance is «now», and the label says so
 *
 * Top-ups and charges belong to the range. The balance does not — «چقدر اعتبار دارم» is a
 * question about this minute, and answering it with a historical figure under a heading
 * that reads as current is how a shop runs out mid-campaign.
 */
export default function OperationsReport({
  period,
  can_export: canExport,
  report_key: reportKey,
  presets,
  rows,
  totals,
  wallet,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);

  const query = (next: Partial<{ from: string | null; to: string | null }> = {}) => {
    const merged = { from, to, ...next };

    return {
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Parameters<typeof query>[0] = {}) => {
    router.get('/reporting/operations', query(next), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const exportHref = `/reporting/operations/export?${new URLSearchParams(query()).toString()}`;

  return (
    <AppShell title="مصرف پیامک">
      <Head title="مصرف پیامک" />

      <PrintLayout.A4
        toolbar={
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Link href="/reporting" className="me-2 text-sm text-primary hover:underline">
                فهرست گزارش‌ها
              </Link>
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
              path="/reporting/operations"
            />
          </div>
        }
      >
        <div className="p-8 print:p-0">
          <header className="mb-6 border-b pb-4">
            <h1 className="text-lg font-bold">مصرف پیامک</h1>
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
            هزینه بر اساس «بخش» است نه تعداد پیامک: هر پیامک فارسی تا ۷۰ نویسه یک بخش حساب می‌شود،
            پس یک کلمهٔ اضافه در یک قالب، هزینهٔ همهٔ ارسال‌های آن را دو برابر می‌کند. «مسدود» یعنی
            گیرنده در فهرست انصراف بوده و پیامک عمداً ارسال نشده — این موفقیت است، نه خطا. اعتبار
            کیف پول مربوط به همین لحظه است، نه پایان بازه.
          </footer>
        </div>
      </PrintLayout.A4>
    </AppShell>
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
