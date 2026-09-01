import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar, withoutEmpty } from '@/components/domain/filter-bar';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface InvoiceRow {
  id: number;
  number: string | null;
  status: string;
  status_label: string;
  issued_at: string | null;
  created_at: string | null;
  party_name: string | null;
  branch_name: string;
  salesperson_name: string | null;
  total: MoneyValue;
  outstanding: MoneyValue;
}

interface Props {
  invoices: { rows: InvoiceRow[]; links: PaginationLink[]; total: number };
  filters: { q: string; status: string | null };
  statuses: Array<{ value: string; label: string }>;
  can: { create: boolean };
}

/**
 * The sales book.
 *
 * What a shop opens to find one invoice among thousands, and the search box takes all
 * three things they might have in front of them: the number on the paper, the customer's
 * name, or — most often — the phone itself, by IMEI. A list that only searched numbers
 * would send staff to a filing cabinet.
 *
 * ## The money columns were aligned on the wrong edge
 *
 * The hand-rolled table set `text-end` on «مبلغ» and «باقی‌مانده». In an RTL table that
 * resolves to physical **left**, which lines up the most-significant digits and leaves the
 * units ragged — measured across twelve rows here at **28px of spread on the total and
 * 61px on the outstanding**, with four distinct right edges each. On the one screen whose
 * job is comparing figures down a column.
 *
 * `DataTable`'s `numeric` flag is physical right and carries the whole argument. The table
 * is now its table, which also brings `secondary` columns that drop below `sm` rather than
 * squeezing seven columns onto a phone.
 */
export default function InvoicesIndex({ invoices, filters, statuses, can }: Props) {
  function visit(changes: Record<string, string | null>): void {
    router.get(
      '/sales',
      // `withoutEmpty`, or clearing a filter leaves `/sales?q=&status=` in the address
      // bar — the same list, and a worse URL to copy to somebody.
      withoutEmpty({ ...filters, ...changes }),
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      header={
        <PageHeader
          title="فاکتورهای فروش"
          actions={
            <>
              <Button asChild variant="outline">
                <Link href="/sales/close">گزارش Z</Link>
              </Button>

              <Button asChild variant="outline">
                <Link href="/sales/quotes">پیش‌فاکتورها</Link>
              </Button>

              {can.create && (
                <Button asChild>
                  <Link href="/sales/pos">
                    <PlusIcon aria-hidden />
                    فروش جدید
                  </Link>
                </Button>
              )}
            </>
          }
        />
      }
    >
      <Head title="فاکتورهای فروش" />

      {/* The debounce, the chip row and the reset all used to be written out here. The
          chips were `size="sm"` — 28px — which is under the touch floor for what is often
          the only way to narrow this list. */}
      <FilterBar
        className="mb-4"
        search={{
          value: filters.q,
          label: 'جستجوی فاکتور',
          placeholder: 'شماره فاکتور، نام مشتری یا IMEI…',
        }}
        groups={[
          {
            key: 'status',
            label: 'وضعیت فاکتور',
            value: filters.status,
            options: statuses,
          },
        ]}
        onChange={visit}
        resultCount={invoices.total}
        resultUnit="فاکتور"
      />

      {invoices.rows.length === 0 ? (
        <EmptyState
          variant={filters.q || filters.status ? 'search' : 'empty'}
          title={filters.q || filters.status ? 'فاکتوری با این فیلتر نیست' : 'هنوز فروشی ثبت نشده'}
          description={
            filters.q || filters.status
              ? 'جستجو یا فیلتر را تغییر دهید.'
              : 'اولین فاکتور را از صفحه فروش ثبت کنید.'
          }
          action={
            can.create && (
              <Button asChild>
                <Link href="/sales/pos">فروش جدید</Link>
              </Button>
            )
          }
        />
      ) : (
        <DataTable
          caption="فاکتورهای فروش، تازه‌ترین اول."
          rows={invoices.rows}
          rowKey={(row) => row.id}
          onRowClick={(row) =>
            router.visit(
              // A parked basket goes back to the till it was parked from; a finalised
              // invoice goes to its document.
              row.status === 'draft' ? `/sales/pos/${row.id}` : `/sales/invoices/${row.id}`
            )
          }
          columns={[
            {
              key: 'number',
              header: 'شماره',
              // A parked basket has no number to burn, so it is named by what it is
              // rather than by an empty cell.
              cell: (row) =>
                row.number ? (
                  <Num value={row.number} variant="ltr" className="font-medium text-primary" />
                ) : (
                  <span className="text-muted-foreground">پیش‌نویس</span>
                ),
            },
            {
              key: 'issued_at',
              header: 'تاریخ',
              cell: (row) => formatJalali(row.issued_at ?? row.created_at),
              secondary: true,
            },
            {
              key: 'party',
              header: 'مشتری',
              cell: (row) => row.party_name ?? 'مشتری گذری',
            },
            {
              key: 'salesperson',
              header: 'فروشنده',
              cell: (row) => row.salesperson_name ?? '—',
              secondary: true,
            },
            {
              key: 'total',
              header: 'مبلغ',
              numeric: true,
              cell: (row) => <Money rial={row.total.value} digits="latin" />,
            },
            {
              key: 'outstanding',
              header: 'باقی‌مانده',
              numeric: true,
              cell: (row) =>
                row.outstanding.value > 0 ? (
                  <span className="text-warning">
                    <Money rial={row.outstanding.value} digits="latin" />
                  </span>
                ) : (
                  <span className="text-muted-foreground">—</span>
                ),
            },
            {
              key: 'status',
              header: 'وضعیت',
              cell: (row) => <StatusBadge status={row.status} />,
            },
          ]}
        />
      )}

      <Pagination links={invoices.links} total={invoices.total} unit="فاکتور" className="mt-4" />
    </AppShell>
  );
}
