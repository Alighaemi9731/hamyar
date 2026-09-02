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

import { InventorySheet } from '../../reporting/inventory-sheet';
import { useReportView } from '../../reporting/report-view';
import {
  INVENTORY_CUTS,
  type InventoryCut,
  type InventoryRow,
  type InventoryTotals,
} from '../../reporting/types';

interface Props {
  cut: InventoryCut;
  as_of: string;
  as_of_jalali: string;
  days: number;
  day_options: number[];
  can_export: boolean;
  totals: InventoryTotals;
  rows: InventoryRow[];
}

/**
 * Stock valuation and dead stock.
 *
 * ## A date, not a range
 *
 * «موجودی» is a figure at an instant, so the filter is one as-of date rather than the
 * range every other report takes. Dead stock swaps it for a threshold in days, because
 * that is how the question is phrased at the counter.
 *
 * ## Devices and items are counted apart
 *
 * A mobile shop's two kinds of stock are two kinds of asset — handsets tracked one by one
 * and accessories tracked by quantity — and the accountant asks for them separately. A
 * single «ارزش کل» would let forty handsets and no accessories read the same as neither.
 *
 * ## Two views
 *
 * The A4 sheet moved to `reporting/inventory-sheet.tsx` unchanged; the default view is
 * built for a monitor. See `Sales.tsx` for the argument and `report-view.tsx` for the toggle.
 */
export default function InventoryReport({
  cut,
  as_of: asOf,
  as_of_jalali: asOfJalali,
  days,
  day_options: dayOptions,
  can_export: canExport,
  totals,
  rows,
}: Props) {
  const [date, setDate] = useState<string | null>(asOf);
  const { showingSheet, actions } = useReportView();

  const dead = cut === 'dead';
  const title = dead ? 'کالای راکد' : 'ارزش موجودی انبار';

  const query = (next: Partial<{ cut: InventoryCut; as_of: string | null; days: number }> = {}) => {
    const merged = { cut, as_of: date, days, ...next };

    return {
      cut: merged.cut,
      as_of: merged.as_of ? formatJalali(merged.as_of, { persianDigits: false }) : '',
      days: String(merged.days),
    };
  };

  const apply = (next: Partial<{ cut: InventoryCut; as_of: string | null; days: number }> = {}) => {
    router.get('/reporting/inventory', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/inventory/export?${new URLSearchParams(query()).toString()}`;

  const toolbar = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {INVENTORY_CUTS.map((entry) => (
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
        {dead ? (
          <div className="grid gap-1.5">
            <Label htmlFor="days">بدون خروج به مدت</Label>
            <div id="days" className="flex flex-wrap gap-2">
              {dayOptions.map((option) => (
                <Button
                  key={option}
                  variant={option === days ? 'default' : 'outline'}
                  aria-pressed={option === days}
                  onClick={() => apply({ days: option })}
                >
                  <Num value={option} variant="table" /> روز
                </Button>
              ))}
            </div>
          </div>
        ) : (
          <>
            <div className="grid gap-1.5">
              <Label htmlFor="as_of">در تاریخ</Label>
              <JDatePicker id="as_of" value={date} onChange={setDate} clearable={false} />
            </div>

            <Button variant="outline" onClick={() => apply()}>
              اعمال تاریخ
            </Button>
          </>
        )}
      </div>
    </div>
  );

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="گزارش"
          title={title}
          back={{ href: '/reporting', label: 'فهرست گزارش‌ها' }}
          description={dead ? `بدون خروج به مدت ${days} روز` : `در تاریخ ${asOfJalali}`}
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
          <InventorySheet
            dead={dead}
            asOfJalali={asOfJalali}
            days={days}
            rows={rows}
            totals={totals}
          />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="ارزش کل" value={totals.value.value} isMoney />
            <StatCard label="ارزش دستگاه‌ها" value={totals.device_value.value} isMoney />
            <StatCard label="دستگاه" value={totals.devices} />
            <StatCard label="قلم کالا" value={totals.items} />
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title={dead ? 'کالای راکدی نیست' : 'موجودی‌ای ثبت نشده است'}
              description={dead ? 'آستانه را کوتاه‌تر کنید.' : 'تاریخ دیگری را امتحان کنید.'}
            />
          ) : (
            <DataTable
              caption={
                dead ? `کالای بدون خروج به مدت ${days} روز.` : `ارزش موجودی در تاریخ ${asOfJalali}.`
              }
              rows={rows}
              rowKey={(row) => `${row.kind}-${row.label}`}
              columns={[
                { key: 'label', header: 'کالا', cell: (row) => row.label },
                {
                  key: 'kind',
                  header: 'نوع',
                  secondary: true,
                  cell: (row) => (row.kind === 'serialized' ? 'دستگاه' : 'کالا'),
                },
                {
                  key: 'quantity',
                  header: 'تعداد',
                  numeric: true,
                  cell: (row) => <Num value={row.quantity} />,
                },
                ...(dead
                  ? [
                      {
                        key: 'idle_days',
                        header: 'روز بدون خروج',
                        numeric: true,
                        cell: (row: InventoryRow) => <Num value={row.idle_days ?? 0} />,
                      },
                      {
                        key: 'last_out',
                        header: 'آخرین خروج',
                        secondary: true,
                        cell: (row: InventoryRow) => row.last_out ?? '—',
                      },
                    ]
                  : [
                      {
                        key: 'unit_cost',
                        header: 'بهای واحد',
                        numeric: true,
                        secondary: true,
                        cell: (row: InventoryRow) => (
                          <Money rial={row.unit_cost?.value ?? 0} digits="latin" />
                        ),
                      },
                    ]),
                {
                  key: 'value',
                  header: 'ارزش',
                  numeric: true,
                  cell: (row) => <Money rial={row.value.value} digits="latin" />,
                },
              ]}
              footer={(column) => {
                if (column.key === 'value') {
                  return <Money rial={totals.value.value} digits="latin" />;
                }
                if (column.key === 'quantity') {
                  return <Num value={totals.items + totals.devices} />;
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
