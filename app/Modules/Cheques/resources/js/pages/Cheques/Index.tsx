import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangleIcon, CalendarClockIcon, FileTextIcon } from 'lucide-react';
import { useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';

import { RegisterForm } from '../../cheques/register-form';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface ChequeRow {
  id: number;
  serial: string;
  bank_name: string;
  party_name: string | null;
  account_name: string | null;
  amount: MoneyValue;
  outstanding: MoneyValue;
  status: string;
  status_label: string;
  due_date: string;
  attempt: number;
}

interface Props {
  direction: string;
  overdue: ChequeRow[];
  due: ChequeRow[];
  totals: { overdue: MoneyValue; due: MoneyValue };
  cheques: { data: ChequeRow[]; links: PaginationLink[]; total: number };
  accounts: Array<{ id: number; name: string }>;
  errors: Record<string, string>;
}

/**
 * The cheque book, and what is falling due.
 *
 * ## Overdue and due-soon come first, and the whole list second
 *
 * A shop with two hundred cheques on file cares about eleven of them this morning. A
 * screen that opens on all two hundred, newest first, is one nobody opens twice.
 *
 * «سررسید گذشته» is derived from the date rather than read from a status, because a cheque
 * nobody has banked three weeks after its due date has no distinguishing status of its own
 * — it is exactly the one nobody is watching.
 */
export default function ChequesIndex({
  direction,
  overdue,
  due,
  totals,
  cheques,
  accounts,
  errors,
}: Props) {
  const [busy, setBusy] = useState<number | null>(null);

  const act = (cheque: ChequeRow, action: string, extra: Record<string, unknown> = {}) => {
    setBusy(cheque.id);
    router.post(
      `/cheques/${cheque.id}/transition`,
      { action, ...extra },
      { preserveScroll: true, onFinish: () => setBusy(null) }
    );
  };

  return (
    <AppShell
      header={
        <PageHeader
          title="چک‌ها"
          actions={
            <>
              <Button variant={direction === 'received' ? 'default' : 'outline'} asChild>
                <Link href="/cheques?direction=received">دریافتی</Link>
              </Button>
              <Button variant={direction === 'issued' ? 'default' : 'outline'} asChild>
                <Link href="/cheques?direction=issued">پرداختی</Link>
              </Button>
            </>
          }
        />
      }
    >
      <Head title="چک‌ها" />

      <div>
        <RegisterForm direction={direction} accounts={accounts} errors={errors} />
      </div>

      {/*
        `ChequeController::act()` validates seven keys — `action`, `account_id`, `party_id`,
        `reason`, `fee`, `recovered`, `occurred_on` — and this page rendered one. A row
        action refused on any of the other six came back as a 302 and the row did not move,
        which on a screen whose buttons are «به بانک» and «برگشت خورد» is a shopkeeper
        pressing a button and watching nothing happen to a cheque worth real money.
      */}
      <FormErrors errors={errors} handled={['cheque']} className="mt-4" />

      {errors.cheque && (
        <p
          role="alert"
          className="mt-4 rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {errors.cheque}
        </p>
      )}

      {overdue.length > 0 && (
        <section className="mt-6 space-y-2">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-destructive">
            <AlertTriangleIcon className="size-4" aria-hidden />
            سررسید گذشته — <Money rial={totals.overdue.value} withUnit />
          </h2>
          <ChequeTable rows={overdue} accounts={accounts} onAct={act} busy={busy} />
        </section>
      )}

      {due.length > 0 && (
        <section className="mt-6 space-y-2">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <CalendarClockIcon className="size-4" aria-hidden />
            دو هفته آینده — <Money rial={totals.due.value} withUnit />
          </h2>
          <ChequeTable rows={due} accounts={accounts} onAct={act} busy={busy} />
        </section>
      )}

      <section className="mt-8 space-y-2">
        <h2 className="text-sm font-semibold">همه چک‌ها ({cheques.total})</h2>
        <ChequeTable rows={cheques.data} accounts={accounts} onAct={act} busy={busy} />
        <Pagination links={cheques.links} total={cheques.total} className="mt-4" />
      </section>
    </AppShell>
  );
}

function ChequeTable({
  rows,
  accounts,
  onAct,
  busy,
}: {
  rows: ChequeRow[];
  accounts: Array<{ id: number; name: string }>;
  onAct: (cheque: ChequeRow, action: string, extra?: Record<string, unknown>) => void;
  busy: number | null;
}) {
  /*
    Columns are built here because the action cell closes over `onAct` and `busy`.

    `numeric` on the amount is the load-bearing change: it was `text-end`, which under
    `dir="rtl"` is physical *left* — it lines the most-significant digits up and leaves the
    units ragged, in a list a shop scans down looking for the cheque that matches a number
    on a piece of paper.
  */
  const columns: Column<ChequeRow>[] = [
    {
      key: 'serial',
      header: 'شماره',
      cell: (row) => (
        <span className="min-w-0">
          {/* A serial is an identifier, not a quantity: LTR, monospaced, never grouped,
              and never in Persian digits — it has to be readable back to a bank. */}
          <Num value={row.serial} variant="ltr" />
          <span className="mt-0.5 block truncate text-2xs text-muted-foreground">
            {row.bank_name}
          </span>
        </span>
      ),
    },
    {
      key: 'party',
      header: 'طرف حساب',
      cell: (row) => row.party_name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'due_date',
      header: 'سررسید',
      secondary: true,
      className: 'whitespace-nowrap',
      cell: (row) => <span className="text-2xs">{formatJalali(row.due_date)}</span>,
    },
    {
      key: 'amount',
      header: 'مبلغ',
      numeric: true,
      cell: (row) => (
        <span>
          <Money rial={row.amount.value} digits="latin" />
          {row.outstanding.value !== row.amount.value && (
            <span className="mt-0.5 block text-2xs text-muted-foreground">
              مانده <Money rial={row.outstanding.value} digits="latin" />
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'وضعیت',
      cell: (row) => (
        <span className="flex flex-wrap items-center gap-1">
          <StatusBadge status={row.status} label={row.status_label} />
          {row.attempt > 1 && (
            <span className="text-2xs text-muted-foreground">نوبت {row.attempt}</span>
          )}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'عملیات',
      // Held to its content width, or the action column takes space from the party name,
      // which is the column somebody actually reads.
      className: 'w-px whitespace-nowrap',
      cell: (row) => (
        // `default` size, not `sm`. These are the controls that move a cheque through its
        // life — deposit it, mark it cleared, record a bounce — and the 28px step stopped
        // being available for anything a thumb lands on (2026-08-31).
        <div className="flex flex-wrap gap-1.5">
          {row.status === 'in_hand' && accounts.length > 0 && (
            <Button
              variant="secondary"
              disabled={busy === row.id}
              onClick={() => onAct(row, 'deposit', { account_id: accounts[0]!.id })}
            >
              به بانک
            </Button>
          )}
          {(row.status === 'deposited' || row.status === 'in_hand') && (
            <Button variant="ghost" disabled={busy === row.id} onClick={() => onAct(row, 'clear')}>
              وصول شد
            </Button>
          )}
          {row.status === 'deposited' && (
            <Button
              variant="ghost"
              disabled={busy === row.id}
              onClick={() => onAct(row, 'bounce', { reason: 'کسر موجودی' })}
            >
              برگشت خورد
            </Button>
          )}
          {row.status === 'bounced' && accounts.length > 0 && (
            <Button
              variant="secondary"
              disabled={busy === row.id}
              onClick={() => onAct(row, 'deposit', { account_id: accounts[0]!.id })}
            >
              ارائه مجدد
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <DataTable
      columns={columns}
      rows={rows}
      rowKey={(row) => row.id}
      caption="فهرست چک‌ها با وضعیت و مبلغ هرکدام"
      empty={
        <EmptyState
          icon={FileTextIcon}
          title="چکی در این فهرست نیست"
          description="چک‌ها هنگام ثبت فروش اقساطی یا از همین صفحه اضافه می‌شوند."
        />
      }
    />
  );
}
