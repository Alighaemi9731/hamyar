import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon, SearchIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

/**
 * The bench queue.
 *
 * Ordered urgent-first then oldest-first, which is how a shop actually triages — a queue
 * sorted newest-first grows a rusty tail of devices nobody looks at again.
 *
 * The Kanban board lands next; this list is the view that works on a phone, and it stays
 * afterwards for the same reason: dragging cards is a desk activity.
 */
export default function TicketsIndex({ tickets, filters, statuses, can }: Props) {
  const [term, setTerm] = useState(filters.q);

  function visit(changes: Record<string, string | boolean | null>): void {
    router.get(
      '/repairs',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      title="تعمیرات"
      actions={
        can.create && (
          <Button asChild>
            <Link href="/repairs/intake">
              <PlusIcon className="size-4" aria-hidden />
              پذیرش دستگاه
            </Link>
          </Button>
        )
      }
    >
      <Head title="تعمیرات" />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <form
          className="relative min-w-56 flex-1"
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
            aria-label="جستجوی تیکت"
            className="ps-9"
            placeholder="شماره قبض، IMEI، مدل یا نام مشتری…"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
          />
        </form>

        <Button
          type="button"
          size="sm"
          variant={filters.mine ? 'default' : 'outline'}
          onClick={() => visit({ mine: !filters.mine })}
        >
          کارهای من
        </Button>
      </div>

      <div className="mb-4 flex flex-wrap gap-1">
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

      {tickets.rows.length === 0 ? (
        <EmptyState
          variant={filters.q || filters.status ? 'search' : 'empty'}
          title={
            filters.q || filters.status ? 'تیکتی با این فیلتر نیست' : 'هنوز دستگاهی پذیرش نشده'
          }
          description={
            filters.q || filters.status
              ? 'جستجو یا فیلتر را تغییر دهید.'
              : 'اولین دستگاه را از صفحه پذیرش ثبت کنید.'
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
        <ul className="space-y-2">
          {tickets.rows.map((ticket) => (
            <li key={ticket.id}>
              <Link
                href={`/repairs/tickets/${ticket.id}`}
                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-card border border-border p-3 hover:bg-muted/30"
              >
                <span className="min-w-0">
                  <span className="flex flex-wrap items-baseline gap-2">
                    <span className="tabular font-medium text-primary">{ticket.code}</span>
                    {ticket.priority === 1 && (
                      <span className="rounded-pill bg-danger/10 px-2 text-2xs text-danger">
                        فوری
                      </span>
                    )}
                    <span className="text-sm">{ticket.device}</span>
                  </span>
                  <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                    <span>{ticket.party_name ?? 'مشتری گذری'}</span>
                    {ticket.device_imei && (
                      <>
                        <span aria-hidden>·</span>
                        <Num value={ticket.device_imei} variant="ltr" />
                      </>
                    )}
                    <span aria-hidden>·</span>
                    <span>{formatJalali(ticket.created_at)}</span>
                    {ticket.technician_name && (
                      <>
                        <span aria-hidden>·</span>
                        <span>{ticket.technician_name}</span>
                      </>
                    )}
                  </span>
                </span>

                <span className="flex shrink-0 items-center gap-2">
                  {ticket.promised_at && (
                    <span
                      className={cn(
                        'text-2xs',
                        new Date(ticket.promised_at) < new Date()
                          ? 'text-danger'
                          : 'text-muted-foreground'
                      )}
                    >
                      وعده {formatJalali(ticket.promised_at)}
                    </span>
                  )}
                  <StatusBadge status={ticket.status} />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <Pagination links={tickets.links} total={tickets.total} unit="تیکت" className="mt-4" />
    </AppShell>
  );
}
