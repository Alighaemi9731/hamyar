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
import { StatCard } from '@/components/domain/stat-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

import { useReportView } from '../../reporting/report-view';
import { TaxSheet } from '../../reporting/tax-sheet';
import {
  TAX_CUTS,
  type ReportPeriod,
  type TaxCut,
  type TaxRow,
  type TaxTotals,
} from '../../reporting/types';

interface Props {
  cut: TaxCut;
  period: ReportPeriod;
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: TaxRow[];
  totals: TaxTotals;
}

/**
 * خلاصه مالیات بر ارزش افزوده — the figures a VAT return is filled in from.
 *
 * ## Months are Jalali months
 *
 * The rows are folded server-side by `Jalali::monthKey()`. A return filed against «مرداد»
 * that quietly contains a week of Tir is a wrong filing, and Postgres has no Jalali
 * calendar to group by.
 *
 * ## The rounding column is not decoration
 *
 * ADR 0009 rule 3: an invoice's total is base + VAT − discount + shipping + rounding, and
 * a summary that hides the last term cannot be tied back to the invoices it summarises.
 * It is usually a rounding artefact and it is always shown, at any amount.
 *
 * ## Two views
 *
 * The A4 sheet moved to `reporting/tax-sheet.tsx` unchanged; the default view is built
 * for a monitor. See `Sales.tsx` for the argument and `report-view.tsx` for the toggle.
 */
export default function TaxReport({
  cut,
  period,
  can_export: canExport,
  report_key: reportKey,
  presets,
  rows,
  totals,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);
  const { showingSheet, actions } = useReportView();

  const byRate = cut === 'rate';

  const query = (next: Partial<{ cut: TaxCut; from: string | null; to: string | null }> = {}) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Parameters<typeof query>[0] = {}) => {
    router.get('/reporting/tax', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/tax/export?${new URLSearchParams(query()).toString()}`;

  const toolbar = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {TAX_CUTS.map((entry) => (
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
      </div>

      <ReportPresets
        reportKey={reportKey}
        presets={presets}
        current={query()}
        path="/reporting/tax"
      />
    </div>
  );

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="گزارش"
          title="خلاصه مالیات بر ارزش افزوده"
          back={{ href: '/reporting', label: 'فهرست گزارش‌ها' }}
          description={`از ${period.from_jalali} تا ${period.to_jalali}`}
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
      <Head title="خلاصه مالیات بر ارزش افزوده" />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <TaxSheet byRate={byRate} period={period} rows={rows} totals={totals} />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="پایهٔ مشمول" value={totals.taxable_base.value} isMoney />
            <StatCard label="پایهٔ معاف" value={totals.exempt_base.value} isMoney />
            <StatCard label="مالیات بر ارزش افزوده" value={totals.vat.value} isMoney />
            <StatCard label="گرد کردن" value={totals.rounding.value} isMoney />
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title="در این بازه فاکتوری ثبت نشده است"
              description="بازه را بازتر کنید."
            />
          ) : (
            <DataTable
              caption={`مالیات بر ارزش افزوده ${byRate ? 'بر اساس نرخ' : 'ماهانه'} از ${period.from_jalali} تا ${period.to_jalali}.`}
              rows={rows}
              rowKey={(row) => row.label}
              columns={[
                { key: 'label', header: byRate ? 'نرخ' : 'ماه', cell: (row) => row.label },
                ...(byRate
                  ? [
                      {
                        key: 'lines',
                        header: 'ردیف',
                        numeric: true,
                        cell: (row: TaxRow) => <Num value={row.lines ?? 0} />,
                      },
                    ]
                  : [
                      {
                        key: 'invoices',
                        header: 'فاکتور',
                        numeric: true,
                        cell: (row: TaxRow) => <Num value={row.invoices ?? 0} />,
                      },
                    ]),
                {
                  key: 'taxable_base',
                  header: 'پایهٔ مشمول',
                  numeric: true,
                  cell: (row) => <Money rial={row.taxable_base.value} digits="latin" />,
                },
                ...(byRate
                  ? []
                  : [
                      {
                        key: 'exempt_base',
                        header: 'پایهٔ معاف',
                        numeric: true,
                        secondary: true,
                        cell: (row: TaxRow) => (
                          <Money rial={row.exempt_base?.value ?? 0} digits="latin" />
                        ),
                      },
                    ]),
                {
                  key: 'vat',
                  header: 'مالیات',
                  numeric: true,
                  cell: (row) => <Money rial={row.vat.value} digits="latin" />,
                },
                ...(byRate
                  ? []
                  : [
                      {
                        key: 'rounding',
                        header: 'گرد کردن',
                        numeric: true,
                        secondary: true,
                        cell: (row: TaxRow) => (
                          <Money rial={row.rounding?.value ?? 0} digits="latin" signed />
                        ),
                      },
                    ]),
              ]}
              footer={(column) => {
                if (column.key === 'taxable_base') {
                  return <Money rial={totals.taxable_base.value} digits="latin" />;
                }
                if (column.key === 'exempt_base') {
                  return <Money rial={totals.exempt_base.value} digits="latin" />;
                }
                if (column.key === 'vat') {
                  return <Money rial={totals.vat.value} digits="latin" />;
                }
                if (column.key === 'rounding') {
                  return <Money rial={totals.rounding.value} digits="latin" signed />;
                }
                return undefined;
              }}
            />
          )}
        </div>
      )}
    </AppShell>
  );
}
