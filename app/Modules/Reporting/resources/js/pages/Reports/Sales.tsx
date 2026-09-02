import { Head, Link, router } from '@inertiajs/react';
import { DownloadIcon, FileTextIcon, PrinterIcon, TableIcon } from 'lucide-react';
import { useState } from 'react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { StatCard } from '@/components/domain/stat-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

import { SalesSheet } from '../../reporting/sales-sheet';
import {
  SALES_CUTS,
  type Cut,
  type ReportPeriod,
  type SalesRow,
  type SalesSummary,
} from '../../reporting/types';

interface Props {
  cut: Cut;
  period: ReportPeriod;
  shows_margin: boolean;
  can_export: boolean;
  summary: SalesSummary;
  rows: SalesRow[];
}

/**
 * The sales report — screen and document.
 *
 * ## Why there are two views and not one
 *
 * This was an A4 sheet rendered inside the app shell. On a 1440 monitor that is a 794px
 * paper column with the rest of the screen empty beside it, and the table inside it is
 * sized for ink: no sorting, no row that can be clicked through to what it counts, and
 * figures set at document scale rather than at a scale you compare down a column.
 *
 * The document is not the problem — a shop mails it to an accountant and it has to keep
 * looking exactly like this. The problem is that the document was also being asked to be
 * the screen.
 *
 * So the sheet moved to `reporting/sales-sheet.tsx` **unchanged**, and the default view is
 * now built for a monitor. «نمای چاپ» switches to the paper.
 *
 * ## The toggle is not a preview route
 *
 * `PrintLayout`'s whole argument is that the sheet on screen *is* the sheet that prints —
 * "a preview that looks nothing like the output is its own bug" — and a separate print URL
 * is exactly the drift it refuses. So this is one page in two modes: pressing «چاپ» shows
 * the sheet and then prints it, so what goes to the printer is what was on the screen a
 * moment earlier. Nothing prints unseen.
 *
 * ## The range survives a change of cut
 *
 * Switching from «روزانه» to «بر اساس کالا» keeps the dates. They are the same question
 * grouped differently, and re-typing a range to look at it another way is the friction that
 * stops people looking.
 *
 * ## Cost columns are absent, not blank
 *
 * A viewer without margin permission gets a four-column table, not a six-column one with
 * two empty columns — empty columns read as "no data" rather than "not for you".
 */
export default function SalesReport({
  cut,
  period,
  shows_margin: showsMargin,
  can_export: canExport,
  summary,
  rows,
}: Props) {
  const [from, setFrom] = useState<string | null>(period.from);
  const [to, setTo] = useState<string | null>(period.to);
  const [showingSheet, setShowingSheet] = useState(false);

  const active = SALES_CUTS.find((entry) => entry.key === cut);
  const unit = active?.unit ?? 'تعداد';

  const query = (next: Partial<{ cut: Cut; from: string | null; to: string | null }>) => {
    const merged = { cut, from, to, ...next };

    return {
      cut: merged.cut,
      // Jalali strings on the wire, Latin digits: `ReportPeriod::fromJalali` accepts
      // either, and the URL is something a shopkeeper copies into a chat message.
      from: merged.from ? formatJalali(merged.from, { persianDigits: false }) : '',
      to: merged.to ? formatJalali(merged.to, { persianDigits: false }) : '',
    };
  };

  const apply = (next: Partial<{ cut: Cut; from: string | null; to: string | null }> = {}) => {
    router.get('/reporting/sales', query(next), { preserveState: true, preserveScroll: true });
  };

  const exportHref = `/reporting/sales/export?${new URLSearchParams(query({})).toString()}`;

  const toolbar = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {SALES_CUTS.map((entry) => (
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
      header={
        <PageHeader
          eyebrow="گزارش"
          title="گزارش فروش"
          back={{ href: '/reporting', label: 'فهرست گزارش‌ها' }}
          description={`${active?.label ?? ''} · از ${period.from_jalali} تا ${period.to_jalali}`}
          actions={
            <>
              <Button
                variant="outline"
                aria-pressed={showingSheet}
                onClick={() => setShowingSheet((open) => !open)}
              >
                {showingSheet ? <TableIcon aria-hidden /> : <FileTextIcon aria-hidden />}
                {/* Not «نمای چاپ»: that name contains «چاپ», so the toggle and the print
                    button beside it announce as overlapping labels — a screen reader
                    reads "نمای چاپ" and "چاپ" one after the other, and only one of them
                    sends anything to a printer. */}
                {showingSheet ? 'نمایش جدول' : 'نمایش برگه'}
              </Button>

              <Button
                variant="outline"
                onClick={() => {
                  // Show the paper first, then print it: what reaches the printer is what
                  // was on the screen a moment earlier.
                  setShowingSheet(true);
                  window.setTimeout(printSheet, 0);
                }}
              >
                <PrinterIcon aria-hidden />
                چاپ
              </Button>

              {canExport ? (
                <Button asChild variant="outline">
                  {/* A real navigation, not an Inertia visit: the response is a file. */}
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
      <Head title="گزارش فروش" />

      {showingSheet ? (
        <PrintLayout.A4 toolbar={toolbar}>
          <SalesSheet
            cut={active}
            period={period}
            summary={summary}
            rows={rows}
            showsMargin={showsMargin}
          />
        </PrintLayout.A4>
      ) : (
        <div className="space-y-8">
          <Card>{toolbar}</Card>

          {/*
            Returns are their own figure, never netted into the sales line: «چقدر فروختیم»
            and «چقدر برگشت خورد» are two questions, and one net number answers neither.
          */}
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="فروش خالص" value={summary.revenue.value} isMoney />
            <StatCard label="تعداد فاکتور" value={summary.invoice_count} />
            <StatCard label="برگشت از فروش" value={summary.returned_revenue.value} isMoney />
            {summary.profit ? (
              <StatCard
                label="سود ناخالص"
                value={summary.profit.value}
                isMoney
                hint={
                  summary.margin_percent === undefined
                    ? undefined
                    : `حاشیه ${summary.margin_percent}٪`
                }
              />
            ) : null}
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title="در این بازه فروشی ثبت نشده است"
              description="بازه را بازتر کنید، یا برش دیگری را امتحان کنید."
            />
          ) : (
            <DataTable
              caption={`فروش ${active?.label ?? ''} از ${period.from_jalali} تا ${period.to_jalali}.`}
              rows={rows}
              rowKey={(row) => row.label}
              columns={[
                {
                  key: 'label',
                  header: active?.heading ?? 'عنوان',
                  cell: (row) => row.label,
                },
                {
                  key: 'count',
                  header: unit,
                  numeric: true,
                  cell: (row) => <Num value={row.count} />,
                },
                {
                  key: 'revenue',
                  header: 'فروش',
                  numeric: true,
                  cell: (row) => <Money rial={row.revenue.value} digits="latin" />,
                },
                ...(showsMargin
                  ? [
                      {
                        key: 'cost',
                        header: 'بهای تمام‌شده',
                        numeric: true,
                        secondary: true,
                        cell: (row: SalesRow) => (
                          <Money rial={row.cost?.value ?? 0} digits="latin" />
                        ),
                      },
                      {
                        key: 'margin',
                        header: 'سود',
                        numeric: true,
                        cell: (row: SalesRow) => (
                          <Money rial={row.margin?.value ?? 0} digits="latin" signed />
                        ),
                      },
                    ]
                  : []),
              ]}
              // The totals row the sheet has never had. A column of revenue that does not
              // add up to the figure in the band above it is the first thing somebody
              // checking a report notices.
              footer={(column) => {
                if (column.key === 'revenue') {
                  return <Money rial={summary.revenue.value} digits="latin" />;
                }

                if (column.key === 'margin' && summary.profit) {
                  return <Money rial={summary.profit.value} digits="latin" signed />;
                }

                if (column.key === 'count') {
                  return <Num value={rows.reduce((sum, row) => sum + row.count, 0)} />;
                }

                return undefined;
              }}
            />
          )}

          <p className="text-2xs text-muted-foreground">ارقام بدون مالیات بر ارزش افزوده است.</p>
        </div>
      )}
    </AppShell>
  );
}
