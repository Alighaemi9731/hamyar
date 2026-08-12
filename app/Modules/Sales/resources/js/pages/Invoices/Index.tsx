import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon, SearchIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
 */
export default function InvoicesIndex({ invoices, filters, statuses, can }: Props) {
  const [term, setTerm] = useState(filters.q);

  function visit(changes: Record<string, string | null>): void {
    router.get(
      '/sales',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      title="فاکتورهای فروش"
      actions={
        <div className="flex items-center gap-2">
          <Button asChild variant="outline">
            <Link href="/sales/quotes">پیش‌فاکتورها</Link>
          </Button>

          {can.create && (
            <Button asChild>
              <Link href="/sales/pos">
                <PlusIcon className="size-4" aria-hidden />
                فروش جدید
              </Link>
            </Button>
          )}
        </div>
      }
    >
      <Head title="فاکتورهای فروش" />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <form
          className="relative min-w-64 flex-1"
          onSubmit={(event) => {
            event.preventDefault();
            visit({ q: term });
          }}
        >
          <SearchIcon
            className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground"
            aria-hidden
          />
          <Input
            aria-label="جستجوی فاکتور"
            className="ps-9"
            placeholder="شماره فاکتور، نام مشتری یا IMEI…"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
          />
        </form>

        <div className="flex flex-wrap items-center gap-1">
          <Button
            type="button"
            size="sm"
            variant={filters.status === null ? 'default' : 'outline'}
            onClick={() => visit({ status: null })}
          >
            همه
          </Button>

          {statuses.map((status) => (
            <Button
              key={status.value}
              type="button"
              size="sm"
              variant={filters.status === status.value ? 'default' : 'outline'}
              onClick={() => visit({ status: status.value })}
            >
              {status.label}
            </Button>
          ))}
        </div>
      </div>

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
        <div className="overflow-x-auto rounded-card border border-border">
          <table className="w-full text-sm">
            <thead className="bg-muted/50 text-2xs text-muted-foreground">
              <tr>
                <th scope="col" className="p-3 text-start font-medium">
                  شماره
                </th>
                <th scope="col" className="p-3 text-start font-medium">
                  تاریخ
                </th>
                <th scope="col" className="p-3 text-start font-medium">
                  مشتری
                </th>
                <th scope="col" className="p-3 text-start font-medium">
                  فروشنده
                </th>
                <th scope="col" className="p-3 text-end font-medium">
                  مبلغ
                </th>
                <th scope="col" className="p-3 text-end font-medium">
                  باقی‌مانده
                </th>
                <th scope="col" className="p-3 text-start font-medium">
                  وضعیت
                </th>
              </tr>
            </thead>

            <tbody>
              {invoices.rows.map((invoice) => (
                <tr key={invoice.id} className="border-t border-border hover:bg-muted/30">
                  <td className="p-3">
                    <Link
                      href={
                        invoice.status === 'draft'
                          ? `/sales/pos/${invoice.id}`
                          : `/sales/invoices/${invoice.id}`
                      }
                      className="tabular font-medium text-primary hover:underline"
                    >
                      {/* A parked basket has no number to burn, so it is named by what
                          it is rather than by an empty cell. */}
                      {invoice.number ?? 'پیش‌نویس'}
                    </Link>
                  </td>
                  <td className="p-3 text-muted-foreground">
                    {formatJalali(invoice.issued_at ?? invoice.created_at)}
                  </td>
                  <td className="p-3">{invoice.party_name ?? 'مشتری گذری'}</td>
                  <td className="p-3 text-muted-foreground">{invoice.salesperson_name ?? '—'}</td>
                  <td className="p-3 text-end">
                    <Money rial={invoice.total.value} digits="latin" />
                  </td>
                  <td className="p-3 text-end">
                    {invoice.outstanding.value > 0 ? (
                      <span className="text-warning">
                        <Money rial={invoice.outstanding.value} digits="latin" />
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </td>
                  <td className="p-3">
                    <StatusBadge status={invoice.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination links={invoices.links} total={invoices.total} unit="فاکتور" className="mt-4" />
    </AppShell>
  );
}
