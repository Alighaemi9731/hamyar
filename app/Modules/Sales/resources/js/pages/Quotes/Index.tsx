import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRightIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar, withoutEmpty } from '@/components/domain/filter-bar';
import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface QuoteRow {
  id: number;
  number: string | null;
  created_at: string | null;
  party_name: string | null;
  branch_name: string;
  total: MoneyValue;
  converted_to: { id: number; number: string | null } | null;
}

interface Props {
  quotes: { rows: QuoteRow[]; links: PaginationLink[]; total: number };
  filters: { q: string; open: boolean };
  can: { create: boolean };
}

/**
 * پیش‌فاکتورها.
 *
 * The date column is doing real work here, not decoration. A quote reserves nothing and
 * moves nothing; all it carries is a price, and in this market a price from five weeks
 * ago is not a price any more. So every row says how old it is, and the default view is
 * the ones still open.
 *
 * ## Converting could fail with nothing on screen
 *
 * «تبدیل به فاکتور» posted and handled no refusal at all — no `onError`, no error region.
 * The server can decline: the quota for invoices can be spent, the quote can already have
 * been converted by somebody else at the other counter, the stock can be gone. Every one
 * of those came back as a redirect that re-rendered an identical page, so the button
 * simply did not work — the exact failure `<FormErrors>` exists to end, on a button that
 * creates a financial document.
 *
 * ## The total was aligned on the wrong edge
 *
 * `text-end` in an RTL table is physical **left**, which lines up the most-significant
 * digits of a Latin numeral and leaves the units ragged. `DataTable`'s `numeric` is
 * physical right; see the flag's own docblock for the measurements.
 */
export default function QuotesIndex({ quotes, filters, can }: Props) {
  const [converting, setConverting] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  function visit(changes: Record<string, string | boolean | null>): void {
    router.get('/sales/quotes', withoutEmpty({ ...filters, ...changes }), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  function convert(id: number): void {
    setConverting(id);
    setErrors({});

    router.post(
      `/sales/quotes/${id}/convert`,
      {},
      {
        preserveScroll: true,
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setConverting(null),
      }
    );
  }

  return (
    <AppShell
      header={
        <PageHeader
          title="پیش‌فاکتورها"
          description="قیمتی که به مشتری داده‌اید — تا وقتی تبدیل نشود، چیزی رزرو یا کم نمی‌شود."
          actions={
            can.create && (
              <Button asChild>
                <Link href="/sales/pos">
                  <PlusIcon aria-hidden />
                  پیش‌فاکتور جدید
                </Link>
              </Button>
            )
          }
        />
      }
    >
      <Head title="پیش‌فاکتورها" />

      {/* Nothing here has a field to sit beside — a refusal to convert is about the
          document, not about an input. */}
      <FormErrors errors={errors} className="mb-6" />

      <FilterBar
        className="mb-6"
        search={{
          value: filters.q,
          label: 'جستجوی پیش‌فاکتور',
          placeholder: 'شماره پیش‌فاکتور یا نام مشتری…',
        }}
        onChange={visit}
        resultCount={quotes.total}
        resultUnit="پیش‌فاکتور"
      >
        <Button
          type="button"
          variant={filters.open ? 'default' : 'outline'}
          aria-pressed={filters.open}
          onClick={() => visit({ open: !filters.open })}
        >
          فقط تبدیل‌نشده‌ها
        </Button>
      </FilterBar>

      {quotes.rows.length === 0 ? (
        <EmptyState
          variant={filters.q || filters.open ? 'search' : 'empty'}
          title={
            filters.q || filters.open
              ? 'پیش‌فاکتوری با این فیلتر نیست'
              : 'هنوز پیش‌فاکتوری صادر نشده'
          }
          description="در صفحه فروش، سبد را ببندید و «پیش‌فاکتور» را بزنید."
          action={
            can.create && (
              <Button asChild>
                <Link href="/sales/pos">رفتن به فروش</Link>
              </Button>
            )
          }
        />
      ) : (
        <DataTable
          caption="پیش‌فاکتورهای صادرشده، تازه‌ترین اول."
          rows={quotes.rows}
          rowKey={(row) => row.id}
          columns={[
            {
              key: 'number',
              header: 'شماره',
              cell: (row) => (
                <Link
                  href={`/sales/invoices/${row.id}`}
                  className="font-medium text-primary hover:underline"
                >
                  <Num value={row.number ?? '—'} variant="ltr" />
                </Link>
              ),
            },
            {
              key: 'created_at',
              header: 'تاریخ',
              cell: (row) => formatJalali(row.created_at),
            },
            {
              key: 'party',
              header: 'مشتری',
              cell: (row) => row.party_name ?? 'مشتری گذری',
            },
            {
              key: 'total',
              header: 'مبلغ',
              numeric: true,
              cell: (row) => <Money rial={row.total.value} digits="latin" />,
            },
            {
              key: 'converted',
              header: 'وضعیت',
              cell: (row) =>
                row.converted_to ? (
                  <Link
                    href={`/sales/invoices/${row.converted_to.id}`}
                    className="text-2xs text-muted-foreground hover:underline"
                  >
                    {/* Both documents survive conversion, so the row says which invoice
                        this became rather than just "done". */}
                    تبدیل شد به {row.converted_to.number}
                  </Link>
                ) : (
                  <Button
                    type="button"
                    variant="outline"
                    disabled={converting === row.id}
                    onClick={() => convert(row.id)}
                  >
                    <ArrowLeftRightIcon aria-hidden />
                    تبدیل به فاکتور
                  </Button>
                ),
            },
          ]}
        />
      )}

      <Pagination links={quotes.links} total={quotes.total} unit="پیش‌فاکتور" className="mt-6" />
    </AppShell>
  );
}
