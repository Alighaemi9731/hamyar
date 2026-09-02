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

import { OperationsSheet } from '../../reporting/operations-sheet';
import { useReportView } from '../../reporting/report-view';
import type {
  OperationsRow,
  OperationsTotals,
  OperationsWallet,
  ReportPeriod,
} from '../../reporting/types';

interface Props {
  period: ReportPeriod;
  can_export: boolean;
  report_key: string;
  presets: ReportPreset[];
  rows: OperationsRow[];
  totals: OperationsTotals;
  wallet: OperationsWallet;
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
 *
 * ## Two views
 *
 * The A4 sheet moved to `reporting/operations-sheet.tsx` unchanged; the default view is
 * built for a monitor. See `Sales.tsx` for the argument and `report-view.tsx` for the toggle.
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
  const { showingSheet, actions } = useReportView();

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

  const toolbar = (
    <div className="space-y-4">
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
        path="/reporting/operations"
      />
    </div>
  );

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="گزارش"
          title="مصرف پیامک"
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
      <Head title="مصرف پیامک" />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <OperationsSheet period={period} rows={rows} totals={totals} wallet={wallet} />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="اعتبار فعلی" value={wallet.balance.value} isMoney hint="همین لحظه" />
            <StatCard label="هزینهٔ بازه" value={totals.cost.value} isMoney />
            <StatCard label="سگمنت" value={totals.segments} />
            <StatCard label="ناموفق" value={totals.failed} />
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title="در این بازه پیامکی ارسال نشده است"
              description="بازه را بازتر کنید."
            />
          ) : (
            <DataTable
              caption={`مصرف پیامک به تفکیک قالب از ${period.from_jalali} تا ${period.to_jalali}.`}
              rows={rows}
              rowKey={(row) => row.label}
              columns={[
                { key: 'label', header: 'قالب', cell: (row) => row.label },
                {
                  key: 'messages',
                  header: 'پیام',
                  numeric: true,
                  cell: (row) => <Num value={row.messages} />,
                },
                {
                  key: 'segments',
                  header: 'سگمنت',
                  numeric: true,
                  cell: (row) => <Num value={row.segments} />,
                },
                {
                  key: 'sent',
                  header: 'ارسال‌شده',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Num value={row.sent} />,
                },
                {
                  key: 'failed',
                  header: 'ناموفق',
                  numeric: true,
                  cell: (row) => <Num value={row.failed} />,
                },
                {
                  key: 'suppressed',
                  header: 'مسدود',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Num value={row.suppressed} />,
                },
                {
                  key: 'queued',
                  header: 'در صف',
                  numeric: true,
                  secondary: true,
                  cell: (row) => <Num value={row.queued} />,
                },
                {
                  key: 'cost',
                  header: 'هزینه',
                  numeric: true,
                  cell: (row) => <Money rial={row.cost.value} digits="latin" />,
                },
              ]}
              footer={(column) => {
                if (column.key === 'messages') return <Num value={totals.messages} />;
                if (column.key === 'segments') return <Num value={totals.segments} />;
                if (column.key === 'failed') return <Num value={totals.failed} />;
                if (column.key === 'cost') {
                  return <Money rial={totals.cost.value} digits="latin" />;
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
