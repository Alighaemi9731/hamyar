import { Head, router } from '@inertiajs/react';
import { AlertTriangleIcon, CalendarClockIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { PageHeader } from '@/components/domain/page-header';
import { FormErrors } from '@/components/domain/form-errors';
import { MoneyField } from '@/components/domain/money-field';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface RowEntry {
  id: number;
  plan_id: number;
  plan_number: string;
  party_name: string | null;
  sequence: number;
  of: number;
  due_at: string;
  amount: MoneyValue;
  outstanding: MoneyValue;
  late_fee: MoneyValue;
  days_late: number;
}

interface Props {
  overdue: RowEntry[];
  due: RowEntry[];
  totals: { overdue: MoneyValue; fees: MoneyValue };
  accounts: Array<{ id: number; name: string }>;
  errors: Record<string, string>;
}

/**
 * Who owes what today, and taking it.
 *
 * The late fee beside each row is computed live, not stored: it is a calculation against
 * the clock until somebody collects, and it becomes a ledger fact at that moment and not
 * before. A row that accrued into the ledger every night would change a customer's balance
 * with no event behind it.
 *
 * The amount box is pre-filled with the outstanding figure plus the fee, because that is
 * what the customer standing there is being asked for — but it stays editable, because
 * part payments are ordinary here.
 */
export default function CollectionDesk({ overdue, due, totals, accounts, errors }: Props) {
  return (
    <AppShell
      header={
        <PageHeader
          title="میز وصول"
          meta={
            <p className="text-sm text-muted-foreground">
              معوق: <Money rial={totals.overdue.value} withUnit />
              {totals.fees.value > 0 && (
                <>
                  {' · '}جریمه: <Money rial={totals.fees.value} withUnit />
                </>
              )}
            </p>
          }
        />
      }
    >
      <Head title="میز وصول اقساط" />

      {/*
        At page level, because the collect form lives inside a per-row component that never
        receives the error bag — so `account_id` and `amount` refusals came back as a 302
        and the row simply did not change. `collect` keeps its own placement below; this
        catches the keys nobody placed.
      */}
      <FormErrors errors={errors} handled={['collect']} className="mt-4" />

      {errors.collect && (
        <p
          role="alert"
          className="mt-4 rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {errors.collect}
        </p>
      )}

      {overdue.length > 0 && (
        <section className="mt-6 space-y-2">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-destructive">
            <AlertTriangleIcon className="size-4" aria-hidden />
            معوق ({overdue.length})
          </h2>
          {overdue.map((row) => (
            <CollectRow key={row.id} row={row} accounts={accounts} />
          ))}
        </section>
      )}

      <section className="mt-8 space-y-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <CalendarClockIcon className="size-4" aria-hidden />
          سررسید پیش‌رو ({due.length})
        </h2>
        {due.length === 0 ? (
          <p className="rounded-card border border-border p-6 text-center text-sm text-muted-foreground">
            قسطی در انتظار نیست.
          </p>
        ) : (
          due.map((row) => <CollectRow key={row.id} row={row} accounts={accounts} />)
        )}
      </section>
    </AppShell>
  );
}

function CollectRow({
  row,
  accounts,
}: {
  row: RowEntry;
  accounts: Array<{ id: number; name: string }>;
}) {
  const toman = useTenantSettings().currency_display === 'toman';
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState(row.outstanding.value + row.late_fee.value);
  const [accountId, setAccountId] = useState(accounts[0]?.id ?? 0);
  const [busy, setBusy] = useState(false);

  return (
    <div className="rounded-card border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium">
            {row.party_name ?? 'مشتری'}
            <span className="ms-2 text-2xs text-muted-foreground">
              {row.plan_number} · قسط {row.sequence} از {row.of}
            </span>
          </p>
          <p className="text-2xs text-muted-foreground">
            سررسید {formatJalali(row.due_at)}
            {row.days_late > 0 && (
              <span className="ms-1 text-destructive">{row.days_late} روز تأخیر</span>
            )}
          </p>
        </div>

        <div className="flex items-center gap-3">
          <span className="tabular text-sm">
            <Money rial={row.outstanding.value} />
            {row.late_fee.value > 0 && (
              <span className="ms-1 text-2xs text-destructive">
                + <Money rial={row.late_fee.value} />
              </span>
            )}
          </span>

          <Button onClick={() => setOpen((o) => !o)}>دریافت</Button>
        </div>
      </div>

      {open && (
        <form
          className="mt-3 grid gap-3 border-t border-border pt-3 sm:grid-cols-3"
          onSubmit={(event) => {
            event.preventDefault();
            setBusy(true);
            router.post(
              `/installments/rows/${row.id}/collect`,
              { account_id: accountId, amount },
              { preserveScroll: true, onFinish: () => setBusy(false) }
            );
          }}
        >
          <div className="space-y-1">
            <Label htmlFor={`amount-${row.id}`}>مبلغ دریافتی</Label>
            <MoneyField id={`amount-${row.id}`} toman={toman} value={amount} onChange={setAmount} />
          </div>

          <div className="space-y-1">
            <Label htmlFor={`account-${row.id}`}>به حساب</Label>
            {/*
              This was a native `<select>`, and it was `h-8` — 28px, under the touch floor —
              rendering the platform's own dropdown, which on Android ignores the app's
              theme entirely: a white system list over a black page.

              It was one of two; the other was on the storefront settings screen and went
              the same way, so the tenant app now has none.
            */}
            <Select
              value={String(accountId)}
              onValueChange={(value) => setAccountId(Number(value))}
            >
              <SelectTrigger id={`account-${row.id}`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                {accounts.map((account) => (
                  <SelectItem key={account.id} value={String(account.id)}>
                    {account.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex items-end">
            <Button type="submit" disabled={busy}>
              ثبت دریافت
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
