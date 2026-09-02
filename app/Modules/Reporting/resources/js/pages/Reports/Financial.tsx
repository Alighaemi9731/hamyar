import { Head, router } from '@inertiajs/react';
import { DownloadIcon } from 'lucide-react';
import { useState } from 'react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { PrintLayout } from '@/components/domain/print-layout';
import { ReportPresets, type ReportPreset } from '@/components/domain/report-presets';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

import { FinancialSheet } from '../../reporting/financial-sheet';
import { useReportView } from '../../reporting/report-view';
import {
  FINANCIAL_TITLES,
  type AgingRow,
  type ChequeOverdue,
  type ChequeRow,
  type FinancialCut,
  type FinancialDirection,
  type InstallmentRow,
  type ReportPeriod,
} from '../../reporting/types';

interface Props {
  cut: FinancialCut;
  cuts: { key: FinancialCut; label: string }[];
  direction: FinancialDirection;
  period: ReportPeriod;
  as_of: string;
  as_of_jalali: string;
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: AgingRow[] | ChequeRow[] | InstallmentRow[];
  totals: Record<string, MoneyValue | number>;
  overdue?: ChequeOverdue;
}

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
 *
 * ## Two views
 *
 * The A4 sheet — all three of its tables — moved to `reporting/financial-sheet.tsx`
 * unchanged; the default view is built for a monitor. See `Sales.tsx` for the argument and
 * `report-view.tsx` for the toggle.
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
  const { showingSheet, actions } = useReportView();

  const isAging = cut === 'aging';

  const query = (
    next: Partial<{
      cut: FinancialCut;
      direction: FinancialDirection;
      from: string | null;
      to: string | null;
      as_of: string | null;
    }> = {}
  ) => {
    const merged = { cut, direction, from, to, as_of: date, ...next };

    return {
      cut: merged.cut,
      direction: merged.direction,
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
  const title = FINANCIAL_TITLES[cut];

  const toolbar = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {cuts.map((entry) => (
          <Button
            key={entry.key}
            variant={entry.key === cut ? 'default' : 'outline'}
            aria-pressed={entry.key === cut}
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
              <div id="direction" className="flex flex-wrap gap-2">
                <Button
                  variant={direction === 'receivable' ? 'default' : 'outline'}
                  aria-pressed={direction === 'receivable'}
                  onClick={() => apply({ direction: 'receivable' })}
                >
                  طلب از مشتری
                </Button>
                <Button
                  variant={direction === 'payable' ? 'default' : 'outline'}
                  aria-pressed={direction === 'payable'}
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
      </div>

      <ReportPresets
        reportKey={reportKey}
        presets={presets}
        current={query()}
        path="/reporting/financial"
      />
    </div>
  );

  const description = isAging
    ? `در تاریخ ${asOfJalali} — ${direction === 'receivable' ? 'طلب از مشتریان' : 'بدهی به تأمین‌کنندگان'}`
    : `از ${period.from_jalali} تا ${period.to_jalali}`;

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="گزارش"
          title={title}
          back={{ href: '/reporting', label: 'فهرست گزارش‌ها' }}
          description={description}
          actions={
            <>
              {actions}
              {canExport ? (
                <Button asChild variant="outline">
                  <a href={exportHref}>
                    <DownloadIcon aria-hidden />
                    خروجی اکسل
                  </a>
                </Button>
              ) : null}
            </>
          }
        />
      }
    >
      <Head title={title} />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <FinancialSheet
            cut={cut}
            title={title}
            isAging={isAging}
            direction={direction}
            asOfJalali={asOfJalali}
            period={period}
            rows={rows}
            totals={totals}
            overdue={overdue}
          />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          {rows.length === 0 ? (
            <EmptyState title="چیزی برای نمایش نیست" description="فیلتر را تغییر دهید." />
          ) : cut === 'aging' ? (
            <DataTable
              caption={`مانده حساب طرف‌ها ${description}.`}
              rows={rows as AgingRow[]}
              rowKey={(row) => row.party_id}
              columns={[
                { key: 'name', header: 'طرف حساب', cell: (row) => row.name },
                { key: 'kind', header: 'نوع', secondary: true, cell: (row) => row.kind },
                {
                  key: 'total',
                  header: 'جمع',
                  numeric: true,
                  cell: (row) => <Money rial={row.total.value} digits="latin" />,
                },
                {
                  key: 'current',
                  header: 'جاری',
                  numeric: true,
                  cell: (row) => <Money rial={row.current.value} digits="latin" />,
                },
                {
                  key: 'days_60',
                  header: 'تا ۶۰ روز',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.days_60.value} digits="latin" />,
                },
                {
                  key: 'days_90',
                  header: 'تا ۹۰ روز',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.days_90.value} digits="latin" />,
                },
                {
                  key: 'older',
                  header: 'بیش از ۹۰',
                  numeric: true,
                  cell: (row) => <Money rial={row.older.value} digits="latin" />,
                },
                {
                  key: 'credit',
                  header: 'بستانکاری',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.credit.value} digits="latin" />,
                },
              ]}
            />
          ) : cut === 'cheques' ? (
            <DataTable
              caption={`تقویم چک‌ها ${description}.`}
              rows={rows as ChequeRow[]}
              rowKey={(row) => row.due_date}
              columns={[
                { key: 'due_date', header: 'سررسید', cell: (row) => row.due_date },
                {
                  key: 'incoming',
                  header: 'دریافتی',
                  numeric: true,
                  cell: (row) => <Money rial={row.incoming.value} digits="latin" />,
                },
                {
                  key: 'outgoing',
                  header: 'پرداختی',
                  numeric: true,
                  cell: (row) => <Money rial={row.outgoing.value} digits="latin" />,
                },
                {
                  key: 'net',
                  header: 'خالص',
                  numeric: true,
                  cell: (row) => <Money rial={row.net.value} digits="latin" signed />,
                },
                {
                  key: 'cleared',
                  header: 'وصول‌شده',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.cleared.value} digits="latin" />,
                },
                {
                  key: 'bounced',
                  header: 'برگشتی',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.bounced.value} digits="latin" />,
                },
              ]}
            />
          ) : (
            <DataTable
              caption={`دفتر اقساط ${description}.`}
              rows={rows as InstallmentRow[]}
              rowKey={(row) => `${row.plan_number}-${row.sequence}`}
              columns={[
                {
                  key: 'plan_number',
                  header: 'طرح',
                  cell: (row) => <Num value={row.plan_number} variant="ltr" />,
                },
                { key: 'party', header: 'طرف حساب', cell: (row) => row.party },
                {
                  key: 'sequence',
                  header: 'قسط',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Num value={row.sequence} />,
                },
                { key: 'due_at', header: 'سررسید', cell: (row) => row.due_at },
                {
                  key: 'amount',
                  header: 'مبلغ',
                  numeric: true,
                  cell: (row) => <Money rial={row.amount.value} digits="latin" />,
                },
                {
                  key: 'collected',
                  header: 'وصول‌شده',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.collected.value} digits="latin" />,
                },
                {
                  key: 'outstanding',
                  header: 'مانده',
                  numeric: true,
                  cell: (row) => <Money rial={row.outstanding.value} digits="latin" />,
                },
                {
                  key: 'status',
                  header: 'وضعیت',
                  cell: (row) => <StatusBadge status={row.status} />,
                },
              ]}
            />
          )}
        </div>
      )}
    </AppShell>
  );
}
