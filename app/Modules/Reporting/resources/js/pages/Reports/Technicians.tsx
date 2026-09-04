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

import { useReportView } from '../../reporting/report-view';
import { TechniciansSheet, turnaround } from '../../reporting/technicians-sheet';
import type { ReportPeriod, TechnicianRow } from '../../reporting/types';

interface Props {
  period: ReportPeriod;
  shows_cost: boolean;
  can_export: boolean;
  rows: TechnicianRow[];
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
 *
 * ## Two views
 *
 * The A4 sheet moved to `reporting/technicians-sheet.tsx` unchanged; the default view is
 * built for a monitor. See `Sales.tsx` for the argument and `report-view.tsx` for the toggle.
 */
export default function TechnicianReport({
  period,
  shows_cost: showsCost,
  can_export: canExport,
  rows,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);
  const { showingSheet, actions } = useReportView();

  const query = () => ({
    from: from ? formatJalali(from, { persianDigits: false }) : '',
    to: to ? formatJalali(to, { persianDigits: false }) : '',
  });

  const apply = () => {
    router.get('/reporting/technicians', query(), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/technicians/export?${new URLSearchParams(query()).toString()}`;

  const delivered = rows.reduce((sum, row) => sum + row.delivered, 0);
  const open = rows.reduce((sum, row) => sum + row.open, 0);

  const toolbar = (
    <div className="flex flex-wrap items-end gap-3">
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
    </div>
  );

  return (
    <AppShell
      width="wide"
      header={
        <PageHeader
          eyebrow="گزارش"
          title="کارکرد تکنسین‌ها"
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
      <Head title="کارکرد تکنسین‌ها" />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <TechniciansSheet period={period} rows={rows} showsCost={showsCost} />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <StatCard label="تحویل‌شده در بازه" value={delivered} />
            <StatCard label="روی میز" value={open} hint="مستقل از بازه" />
            <StatCard label="تکنسین" value={rows.length} />
          </div>

          {rows.length === 0 ? (
            <EmptyState title="در این بازه تحویلی ثبت نشده است" description="بازه را بازتر کنید." />
          ) : (
            <DataTable
              caption={`کارکرد تکنسین‌ها از ${period.from_jalali} تا ${period.to_jalali}.`}
              rows={rows}
              rowKey={(row) => row.technician}
              columns={[
                { key: 'technician', header: 'تکنسین', cell: (row) => row.technician },
                {
                  key: 'delivered',
                  header: 'تحویل‌شده',
                  numeric: true,
                  cell: (row) => <Num value={row.delivered} />,
                },
                {
                  key: 'open',
                  header: 'روی میز (مستقل از بازه)',
                  numeric: true,
                  cell: (row) => <Num value={row.open} />,
                },
                {
                  key: 'turnaround',
                  header: 'میانگین زمان',
                  cell: (row) => turnaround(row.avg_turnaround_hours),
                },
                ...(showsCost
                  ? [
                      {
                        key: 'parts_cost',
                        header: 'هزینهٔ قطعات',
                        numeric: true,
                        secondary: true,
                        cell: (row: TechnicianRow) => (
                          <Money rial={row.parts_cost?.value ?? 0} digits="latin" />
                        ),
                      },
                    ]
                  : []),
              ]}
              footer={(column) => {
                if (column.key === 'delivered') return <Num value={delivered} />;
                if (column.key === 'open') return <Num value={open} />;
                return undefined;
              }}
            />
          )}
        </div>
      )}
    </AppShell>
  );
}
