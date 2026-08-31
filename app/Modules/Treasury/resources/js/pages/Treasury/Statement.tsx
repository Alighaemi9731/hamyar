import { Head, Link, router } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
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

  const submit = (undo: boolean) =>
    router.post(
      `/treasury/accounts/${account.id}/reconcile`,
      { entry_ids: selected, undo },
      { preserveScroll: true, onSuccess: () => setSelected([]) }
    );

  return (
    <AppShell>
      <Head title={`گردش ${account.name}`} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">{account.name}</h1>
          <p className="text-sm text-muted-foreground">
            مانده: <Money rial={closing.value} withUnit />
          </p>
        </div>

        <Button variant="outline" asChild>
          <Link href="/treasury">بازگشت</Link>
        </Button>
      </div>

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

      <div className="mt-6 overflow-x-auto rounded-card border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-2xs text-muted-foreground">
              {account.holds_money && <th className="p-3" />}
              <th className="p-3 text-start font-medium">تاریخ</th>
              <th className="p-3 text-start font-medium">شرح</th>
              <th className="p-3 text-end font-medium">بدهکار</th>
              <th className="p-3 text-end font-medium">بستانکار</th>
              <th className="p-3 text-end font-medium">مانده</th>
            </tr>
          </thead>
          <tbody>
            {entries.data.map((entry) => (
              <tr key={entry.id} className="border-b border-border last:border-0">
                {account.holds_money && (
                  <td className="p-3">
                    <input
                      type="checkbox"
                      className="size-4 accent-primary"
                      aria-label={`انتخاب ردیف ${entry.id}`}
                      checked={selected.includes(entry.id)}
                      onChange={() => toggle(entry.id)}
                    />
                  </td>
                )}
                <td className="p-3 whitespace-nowrap text-2xs">
                  {formatJalali(entry.occurred_at)}
                  {entry.reconciled && <span className="ms-1 text-success">✓</span>}
                </td>
                <td className="p-3">{entry.description}</td>
                <td className="p-3 text-end tabular">
                  {entry.debit.value > 0 && <Money rial={entry.debit.value} />}
                </td>
                <td className="p-3 text-end tabular">
                  {entry.credit.value > 0 && <Money rial={entry.credit.value} />}
                </td>
                <td className="p-3 text-end font-medium tabular">
                  <Money rial={entry.running.value} />
                </td>
              </tr>
            ))}
            {entries.data.length === 0 && (
              <tr>
                <td colSpan={6} className="p-6 text-center text-sm text-muted-foreground">
                  گردشی ثبت نشده است. مانده اولیه: <Money rial={opening.value} withUnit />
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <Pagination links={entries.links} total={entries.total} className="mt-4" />
    </AppShell>
  );
}
