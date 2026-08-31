import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangleIcon, CalendarClockIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { RegisterForm } from './RegisterForm';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
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
  if (rows.length === 0) {
    return (
      <p className="rounded-card border border-border p-6 text-center text-sm text-muted-foreground">
        چکی نیست.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto rounded-card border border-border">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border text-2xs text-muted-foreground">
            <th className="p-3 text-start font-medium">شماره</th>
            <th className="p-3 text-start font-medium">طرف حساب</th>
            <th className="p-3 text-start font-medium">سررسید</th>
            <th className="p-3 text-end font-medium">مبلغ</th>
            <th className="p-3 text-start font-medium">وضعیت</th>
            <th className="p-3 text-start font-medium">عملیات</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id} className="border-b border-border last:border-0">
              <td className="p-3">
                <span className="tabular" dir="ltr">
                  {row.serial}
                </span>
                <span className="ms-2 text-2xs text-muted-foreground">{row.bank_name}</span>
              </td>
              <td className="p-3">{row.party_name ?? '—'}</td>
              <td className="p-3 whitespace-nowrap text-2xs">{formatJalali(row.due_date)}</td>
              <td className="p-3 text-end tabular">
                <Money rial={row.amount.value} />
                {row.outstanding.value !== row.amount.value && (
                  <span className="block text-2xs text-muted-foreground">
                    مانده <Money rial={row.outstanding.value} />
                  </span>
                )}
              </td>
              <td className="p-3">
                <StatusBadge status={row.status} label={row.status_label} />
                {row.attempt > 1 && (
                  <span className="ms-1 text-2xs text-muted-foreground">نوبت {row.attempt}</span>
                )}
              </td>
              <td className="p-3">
                <div className="flex flex-wrap gap-1">
                  {row.status === 'in_hand' && accounts.length > 0 && (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={busy === row.id}
                      onClick={() => onAct(row, 'deposit', { account_id: accounts[0]!.id })}
                    >
                      به بانک
                    </Button>
                  )}
                  {(row.status === 'deposited' || row.status === 'in_hand') && (
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={busy === row.id}
                      onClick={() => onAct(row, 'clear')}
                    >
                      وصول شد
                    </Button>
                  )}
                  {row.status === 'deposited' && (
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={busy === row.id}
                      onClick={() => onAct(row, 'bounce', { reason: 'کسر موجودی' })}
                    >
                      برگشت خورد
                    </Button>
                  )}
                  {row.status === 'bounced' && accounts.length > 0 && (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={busy === row.id}
                      onClick={() => onAct(row, 'deposit', { account_id: accounts[0]!.id })}
                    >
                      ارائه مجدد
                    </Button>
                  )}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
