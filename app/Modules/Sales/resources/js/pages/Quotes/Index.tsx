import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRightIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
 */
export default function QuotesIndex({ quotes, filters, can }: Props) {
  const [term, setTerm] = useState(filters.q);

  function visit(changes: Record<string, string | boolean | null>): void {
    router.get(
      '/sales/quotes',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      title="پیش‌فاکتورها"
      actions={
        can.create && (
          <Button asChild>
            <Link href="/sales/pos">
              <PlusIcon className="size-4" aria-hidden />
              پیش‌فاکتور جدید
            </Link>
          </Button>
        )
      }
    >
      <Head title="پیش‌فاکتورها" />

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
            aria-label="جستجوی پیش‌فاکتور"
            className="ps-9"
            placeholder="شماره پیش‌فاکتور یا نام مشتری…"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
          />
        </form>

        <Button
          type="button"
          size="sm"
          variant={filters.open ? 'default' : 'outline'}
          onClick={() => visit({ open: !filters.open })}
        >
          فقط تبدیل‌نشده‌ها
        </Button>
      </div>

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
                <th scope="col" className="p-3 text-end font-medium">
                  مبلغ
                </th>
                <th scope="col" className="p-3 text-start font-medium">
                  وضعیت
                </th>
              </tr>
            </thead>

            <tbody>
              {quotes.rows.map((quote) => (
                <tr key={quote.id} className="border-t border-border hover:bg-muted/30">
                  <td className="p-3">
                    <Link
                      href={`/sales/invoices/${quote.id}`}
                      className="tabular font-medium text-primary hover:underline"
                    >
                      {quote.number}
                    </Link>
                  </td>
                  <td className="p-3 text-muted-foreground">{formatJalali(quote.created_at)}</td>
                  <td className="p-3">{quote.party_name ?? 'مشتری گذری'}</td>
                  <td className="p-3 text-end">
                    <Money rial={quote.total.value} digits="latin" />
                  </td>
                  <td className="p-3">
                    {quote.converted_to ? (
                      <Link
                        href={`/sales/invoices/${quote.converted_to.id}`}
                        className="text-2xs text-muted-foreground hover:underline"
                      >
                        {/* Both documents survive conversion, so the row says which
                            invoice this became rather than just "done". */}
                        تبدیل شد به {quote.converted_to.number}
                      </Link>
                    ) : (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => router.post(`/sales/quotes/${quote.id}/convert`)}
                      >
                        <ArrowLeftRightIcon className="size-4" aria-hidden />
                        تبدیل به فاکتور
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination links={quotes.links} total={quotes.total} unit="پیش‌فاکتور" className="mt-4" />
    </AppShell>
  );
}
