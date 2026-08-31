import { Head, router } from '@inertiajs/react';
import { CheckIcon, ReceiptIcon } from 'lucide-react';
import { useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface EntryRow {
  id: number;
  description: string | null;
  debit: MoneyValue;
  credit: MoneyValue;
  running: MoneyValue;
  occurred_at: string;
  reconciled: boolean;
  actor: string | null;
}

interface Props {
  account: { id: number; name: string; type: string; holds_money: boolean };
  opening: MoneyValue;
  closing: MoneyValue;
  entries: { data: EntryRow[]; links: PaginationLink[]; total: number };
  errors: Record<string, string>;
}

/**
 * One account's history, newest first, with a running balance that has to add up.
 *
 * The bottom line is the figure a shopkeeper checks against the treasury page. If the two
 * ever disagree the whole screen stops being believed, so the running balance is computed
 * in the order the rows are displayed — see `AccountStatement`.
 *
 * Ticking an entry asserts «این را با صورتحساب بانک دیدم». It moves no money, which is why
 * un-ticking is allowed: refusing would leave a false statement in place permanently.
 */
export default function AccountStatementPage({
  account,
  opening,
  closing,
  entries,
  errors,
}: Props) {
  const [selected, setSelected] = useState<number[]>([]);

  const toggle = (id: number) =>
    setSelected((current) =>
      current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
    );

  /*
    Built inside the component because two columns close over selection state. `numeric`
    on the three money columns is the point of the conversion: they were `text-end`, which
    in RTL aligns the most-significant digits and leaves the units ragged — on a statement
    read against a bank's.
  */
  const columns: Column<EntryRow>[] = [
    ...(account.holds_money
      ? [
          {
            key: 'select',
            header: 'انتخاب',
            // Held to its content width so the tick column does not steal space from
            // the description, which is the only column that wants to grow.
            className: 'w-px p-0',
            cell: (row: EntryRow) => (
              // The box stays 16px and the target is 40. A checkbox drawn at 40px reads
              // as a button; one you cannot hit with a thumb is the control somebody
              // taps forty times in a row against a bank statement.
              <label className="flex min-h-10 cursor-pointer items-center justify-center px-3">
                <input
                  type="checkbox"
                  className="size-4 accent-primary"
                  aria-label={`انتخاب ردیف ${row.id}`}
                  checked={selected.includes(row.id)}
                  onChange={() => toggle(row.id)}
                />
              </label>
            ),
          } satisfies Column<EntryRow>,
        ]
      : []),
    {
      key: 'occurred_at',
      header: 'تاریخ',
      className: 'whitespace-nowrap',
      cell: (row) => (
        <span className="text-2xs">
          {formatJalali(row.occurred_at)}
          {row.reconciled && (
            <span
              className="ms-1 text-success"
              title="مغایرت‌گیری‌شده"
              aria-label="مغایرت‌گیری‌شده"
            >
              ✓
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'description',
      header: 'شرح',
      cell: (row) => row.description ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'debit',
      header: 'بدهکار',
      numeric: true,
      cell: (row) => (row.debit.value > 0 ? <Money rial={row.debit.value} digits="latin" /> : null),
    },
    {
      key: 'credit',
      header: 'بستانکار',
      numeric: true,
      cell: (row) =>
        row.credit.value > 0 ? <Money rial={row.credit.value} digits="latin" /> : null,
    },
    {
      key: 'running',
      header: 'مانده',
      numeric: true,
      cell: (row) => <Money rial={row.running.value} digits="latin" className="font-medium" />,
    },
  ];

  const submit = (undo: boolean) =>
    router.post(
      `/treasury/accounts/${account.id}/reconcile`,
      { entry_ids: selected, undo },
      { preserveScroll: true, onSuccess: () => setSelected([]) }
    );

  return (
    <AppShell
      header={
        /* «بازگشت» was an outline button sitting beside the page's real actions, which
           made "where I came from" compete with "what I can do here". As a back link it
           reads as the former, above the title, where it belongs. */
        <PageHeader
          eyebrow="گردش حساب"
          title={account.name}
          back={{ href: '/treasury', label: 'خزانه‌داری' }}
          meta={
            <p className="text-sm text-muted-foreground">
              مانده: <Money rial={closing.value} withUnit />
            </p>
          }
        />
      }
    >
      <Head title={`گردش ${account.name}`} />

      {errors.reconcile && (
        <p
          role="alert"
          className="mt-4 rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {errors.reconcile}
        </p>
      )}

      {account.holds_money && selected.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-2 rounded-control border border-border px-3 py-2">
          <span className="text-sm">{selected.length} ردیف انتخاب شده</span>
          <Button size="sm" onClick={() => submit(false)}>
            <CheckIcon className="size-4" aria-hidden />
            تأیید مغایرت‌گیری
          </Button>
          <Button size="sm" variant="ghost" onClick={() => submit(true)}>
            برداشتن تأیید
          </Button>
        </div>
      )}

      {/*
        Every money column was `text-end`, which in RTL is physical *left* — it aligns the
        most-significant digits and leaves the units ragged, on a statement whose whole
        purpose is reading a column of figures against a bank's. `DataTable`'s `numeric`
        flag is that fix.
      */}
      <DataTable
        className="mt-6"
        columns={columns}
        rows={entries.data}
        rowKey={(row) => row.id}
        caption={`گردش حساب ${account.name}`}
        empty={
          <EmptyState
            icon={ReceiptIcon}
            title="گردشی ثبت نشده است"
            description="این حساب هنوز حرکتی نداشته است. مانده اولیه‌اش همان مانده‌ای است که بالا آمده."
          />
        }
      />

      <Pagination links={entries.links} total={entries.total} className="mt-4" />
    </AppShell>
  );
}
