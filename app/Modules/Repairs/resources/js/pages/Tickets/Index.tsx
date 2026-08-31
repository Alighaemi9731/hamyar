import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar, withoutEmpty } from '@/components/domain/filter-bar';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';

interface TicketRow {
  id: number;
  code: string;
  status: string;
  status_label: string;
  device: string;
  device_imei: string | null;
  party_name: string | null;
  technician_name: string | null;
  branch_name: string;
  priority: number;
  promised_at: string | null;
  created_at: string | null;
  ready_at: string | null;
}

interface Props {
  tickets: { rows: TicketRow[]; links: PaginationLink[]; total: number };
  filters: { q: string; status: string | null; mine: boolean };
  statuses: Array<{ value: string; label: string }>;
  columns: string[];
  can: { create: boolean };
}

/** Overdue against now, which is the only comparison this list makes. */
function isLate(promisedAt: string | null): boolean {
  return promisedAt !== null && new Date(promisedAt) < new Date();
}

/**
 * The bench queue.
 *
 * Ordered urgent-first then oldest-first, which is how a shop actually triages — a queue
 * sorted newest-first grows a rusty tail of devices nobody looks at again.
 *
 * The board is the desk view; this is the one that works on a phone, and it stays for the
 * same reason: dragging cards is a desk activity.
 *
 * ## The eleven filter controls were 28px
 *
 * A search box and ten status chips, each hand-rolled at `size="sm"`. On this screen the
 * status chip is often the only way to narrow the queue, which makes it a primary control
 * on a phone rather than a decoration — `FilterBar` puts them at the 40px floor, moves
 * them into a sheet below `md` so ten chips cannot push the list below the fold, and
 * announces the result count, which a filtered list otherwise changes silently.
 *
 * `withoutEmpty` is why clearing a filter gives `/repairs` and not `/repairs?q=&status=`.
 *
 * ## A table, not a card list
 *
 * The queue is scanned down a column — which device is late, which is unassigned — and
 * that is what a table is for. `DataTable` also carries the RTL numeric decision and the
 * `secondary` columns that drop below `sm`, so the phone keeps the code, the device and
 * the status and loses the technician and the branch.
 */
export default function TicketsIndex({ tickets, filters, statuses, can }: Props) {
  function visit(changes: Record<string, string | boolean | null>): void {
    router.get('/repairs', withoutEmpty({ ...filters, ...changes }), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  const filtered = Boolean(filters.q || filters.status || filters.mine);

  return (
    <AppShell
      header={
        <PageHeader
          title="تعمیرات"
          description="صف کار روی میز — فوری‌ها اول، بعد قدیمی‌ترین‌ها."
          actions={
            <>
              <Button asChild variant="outline">
                <Link href="/repairs/board">تخته</Link>
              </Button>
              {can.create && (
                <Button asChild>
                  <Link href="/repairs/intake">
                    <PlusIcon aria-hidden />
                    پذیرش دستگاه
                  </Link>
                </Button>
              )}
            </>
          }
        />
      }
    >
      <Head title="تعمیرات" />

      <FilterBar
        className="mb-6"
        search={{
          value: filters.q,
          label: 'جستجوی تیکت',
          placeholder: 'شماره قبض، IMEI، مدل یا نام مشتری…',
        }}
        groups={[
          {
            key: 'status',
            label: 'وضعیت',
            value: filters.status,
            options: statuses.map((status) => ({ value: status.value, label: status.label })),
          },
        ]}
        onChange={visit}
        resultCount={tickets.total}
        resultUnit="تیکت"
      >
        {/*
          Not a chip group: «کارهای من» is a toggle against the signed-in technician rather
          than one value among several, and `FilterBar` keeps this slot for exactly the
          filter a chip row cannot express.
        */}
        <Button
          type="button"
          variant={filters.mine ? 'default' : 'outline'}
          aria-pressed={filters.mine}
          onClick={() => visit({ mine: !filters.mine })}
        >
          کارهای من
        </Button>
      </FilterBar>

      {tickets.rows.length === 0 ? (
        <EmptyState
          variant={filtered ? 'search' : 'empty'}
          title={filtered ? 'تیکتی با این فیلتر نیست' : 'هنوز دستگاهی پذیرش نشده'}
          description={
            filtered ? 'جستجو یا فیلتر را تغییر دهید.' : 'اولین دستگاه را از صفحه پذیرش ثبت کنید.'
          }
          action={
            can.create && (
              <Button asChild>
                <Link href="/repairs/intake">پذیرش دستگاه</Link>
              </Button>
            )
          }
        />
      ) : (
        <DataTable
          caption="تیکت‌های تعمیر، فوری‌ها اول و بعد قدیمی‌ترین‌ها."
          rows={tickets.rows}
          rowKey={(row) => row.id}
          onRowClick={(row) => router.visit(`/repairs/tickets/${row.id}`)}
          columns={[
            {
              key: 'code',
              header: 'قبض',
              cell: (row) => (
                <span className="flex flex-wrap items-baseline gap-2">
                  <Num value={row.code} variant="ltr" className="font-medium text-primary" />
                  {row.priority === 1 && (
                    <span className="rounded-pill bg-danger/10 px-2 text-2xs text-danger">
                      فوری
                    </span>
                  )}
                </span>
              ),
            },
            {
              key: 'device',
              header: 'دستگاه',
              cell: (row) => (
                <span className="block">
                  <span className="block">{row.device}</span>
                  {row.device_imei && (
                    <Num
                      value={row.device_imei}
                      variant="ltr"
                      className="block text-2xs text-muted-foreground"
                    />
                  )}
                </span>
              ),
            },
            {
              key: 'party',
              header: 'مشتری',
              cell: (row) => row.party_name ?? 'مشتری گذری',
            },
            {
              key: 'technician',
              header: 'تعمیرکار',
              cell: (row) => row.technician_name ?? '—',
              secondary: true,
            },
            {
              key: 'promised_at',
              header: 'وعده',
              cell: (row) =>
                row.promised_at ? (
                  // Late is said in words as well as colour: a shopkeeper who cannot pick
                  // the danger red out of a bright counter still needs to see it.
                  <span className={cn(isLate(row.promised_at) && 'text-danger')}>
                    {formatJalali(row.promised_at)}
                    {isLate(row.promised_at) && ' — گذشته'}
                  </span>
                ) : (
                  '—'
                ),
              secondary: true,
            },
            {
              key: 'status',
              header: 'وضعیت',
              cell: (row) => <StatusBadge status={row.status} />,
            },
          ]}
        />
      )}

      <Pagination links={tickets.links} total={tickets.total} unit="تیکت" className="mt-6" />
    </AppShell>
  );
}
