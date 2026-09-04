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
import { StatCard } from '@/components/domain/stat-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

import { ProfitSheet } from '../../reporting/profit-sheet';
import { useReportView } from '../../reporting/report-view';
import {
  PROFIT_CUTS,
  type ProfitCut,
  type ProfitRow,
  type ProfitSummary,
  type ReportPeriod,
} from '../../reporting/types';

interface Props {
  cut: ProfitCut;
  period: ReportPeriod;
  can_export: boolean;
  summary: ProfitSummary;
  rows: ProfitRow[];
}

/**
 * «از چی سود کردیم» — the profit report, screen and document.
 *
 * ## Rows are ordered by profit, and that is the whole point
 *
 * The sales report puts the best sellers first. This puts the best *earners* first, and
 * the two lists differ: a case sold two hundred times out-earns a phone that moved twice.
 * The ordering happens in SQL, before the limit, so a low-volume high-margin line cannot
 * be discarded before it is ranked.
 *
 * ## No permission branch on this page
 *
 * Unlike the sales report, there is nothing to hide — a viewer who may not see margin
 * never reaches this component, because the controller refuses the request and the report
 * index does not list it. Rendering a "maybe you can see cost" variant here would put the
 * decision in two places, and the second one always drifts.
 *
 * ## The IMEI stays Latin and LTR
 *
 * It is read down a phone line and typed into HAMTA (design-system rule 3), so it is
 * isolated with `dir="ltr"` rather than left to the bidi algorithm, which reorders a
 * fifteen-digit number sitting in a Persian sentence.
 *
 * ## Two views
 *
 * The A4 sheet moved to `reporting/profit-sheet.tsx` unchanged; the default view is built
 * for a monitor. See `Sales.tsx` for the argument and `report-view.tsx` for the toggle.
 */
export default function ProfitReport({ cut, period, can_export: canExport, summary, rows }: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);
  const { showingSheet, actions } = useReportView();

  const active = PROFIT_CUTS.find((entry) => entry.key === cut);
  const perUnit = cut === 'imei';

  const query = (next: Partial<{ cut: ProfitCut; from: string | null; to: string | null }>) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (
    next: Partial<{ cut: ProfitCut; from: string | null; to: string | null }> = {}
  ) => {
    router.get('/reporting/profit', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/profit/export?${new URLSearchParams(query({})).toString()}`;

  const toolbar = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {PROFIT_CUTS.map((entry) => (
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
    </div>
  );

  return (
    <AppShell
      width="wide"
      header={
        <PageHeader
          eyebrow="گزارش"
          title="گزارش سود"
          back={{ href: '/reporting', label: 'فهرست گزارش‌ها' }}
          description={`${active?.label ?? ''} · از ${period.from_jalali} تا ${period.to_jalali}`}
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
      <Head title="گزارش سود" />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <ProfitSheet
            cut={active}
            perUnit={perUnit}
            period={period}
            summary={summary}
            rows={rows}
          />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="فروش" value={summary.revenue.value} isMoney />
            <StatCard label="بهای تمام‌شده" value={summary.cost.value} isMoney />
            <StatCard
              label="سود ناخالص"
              value={summary.profit.value}
              isMoney
              hint={`حاشیه ${summary.margin_percent}٪`}
            />
            <StatCard label="تعداد فاکتور" value={summary.invoice_count} />
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title="در این بازه فروشی ثبت نشده است"
              description="بازه را بازتر کنید، یا برش دیگری را امتحان کنید."
            />
          ) : (
            <DataTable
              caption={`سود ${active?.label ?? ''} از ${period.from_jalali} تا ${period.to_jalali}، پرسودترین اول.`}
              rows={rows}
              // The label is the IMEI on the per-device cut and the group name otherwise —
              // unique either way, which is what the server groups by.
              rowKey={(row) => row.label}
              columns={[
                {
                  key: 'label',
                  header: active?.heading ?? 'عنوان',
                  cell: (row) => (perUnit ? <Num value={row.label} variant="ltr" /> : row.label),
                },
                ...(perUnit
                  ? [
                      {
                        key: 'product',
                        header: 'کالا',
                        cell: (row: ProfitRow) => row.product,
                      },
                      {
                        key: 'invoice',
                        header: 'فاکتور',
                        secondary: true,
                        cell: (row: ProfitRow) => <Num value={row.invoice} variant="ltr" />,
                      },
                      {
                        key: 'sold_at',
                        header: 'تاریخ فروش',
                        secondary: true,
                        cell: (row: ProfitRow) => row.sold_at,
                      },
                    ]
                  : [
                      {
                        key: 'count',
                        header: 'تعداد',
                        numeric: true,
                        cell: (row: ProfitRow) => <Num value={row.count} />,
                      },
                    ]),
                {
                  key: 'revenue',
                  header: 'فروش',
                  numeric: true,
                  cell: (row) => <Money rial={row.revenue.value} digits="latin" />,
                },
                {
                  key: 'cost',
                  header: 'بهای تمام‌شده',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Money rial={row.cost.value} digits="latin" />,
                },
                {
                  key: 'margin',
                  header: 'سود',
                  numeric: true,
                  cell: (row) => <Money rial={row.margin.value} digits="latin" signed />,
                },
              ]}
              footer={(column) => {
                if (column.key === 'revenue') {
                  return <Money rial={summary.revenue.value} digits="latin" />;
                }

                if (column.key === 'cost') {
                  return <Money rial={summary.cost.value} digits="latin" />;
                }

                if (column.key === 'margin') {
                  return <Money rial={summary.profit.value} digits="latin" signed />;
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
