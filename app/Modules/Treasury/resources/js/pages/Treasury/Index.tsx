import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftRightIcon, BanknoteIcon, CreditCardIcon, LandmarkIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { MoneyField } from '@/components/domain/money-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import type { MoneyValue } from '@/types';

interface AccountRow {
  id: number;
  name: string;
  type: string;
  bank_name: string | null;
  balance: MoneyValue;
  unreconciled: MoneyValue;
}

interface HeadingRow {
  id: number;
  name: string;
  type: string;
  total: MoneyValue;
}

interface Props {
  accounts: AccountRow[];
  headings: HeadingRow[];
  total: MoneyValue;
  errors: Record<string, string>;
}

const ICON: Record<string, typeof BanknoteIcon> = {
  cash: BanknoteIcon,
  bank: LandmarkIcon,
  pos_terminal: CreditCardIcon,
};

const TYPE_LABEL: Record<string, string> = {
  cash: 'صندوق',
  bank: 'بانک',
  pos_terminal: 'کارتخوان',
  expense: 'هزینه',
  income: 'درآمد',
  sales: 'فروش',
  inventory: 'موجودی',
  cheques_receivable: 'چک نزد ما',
  cheques_in_collection: 'چک در جریان وصول',
  cheques_returned: 'چک برگشتی',
  cheques_payable: 'اسناد پرداختنی',
};

/**
 * Where the shop's money is.
 *
 * ## Places and headings are two lists, deliberately
 *
 * A till, a bank account and a کارتخوان hold balances somebody can count or check against
 * a statement. A sales or rent account is a classification — asking «چقدر توی حساب اجاره
 * داریم؟» is a category error, and a single table would invite exactly that question every
 * day until somebody acted on the answer.
 *
 * So headings appear below, under their own heading, labelled «جمع» rather than «مانده»:
 * a total spent under a name, not money sitting anywhere.
 *
 * ## The unreconciled figure sits beside the balance
 *
 * A balance that is right with half its entries unticked is a shop that has checked
 * nothing. Putting the two side by side is what turns "the bank looks fine" into "four of
 * these have never been confirmed".
 */
export default function TreasuryIndex({ accounts, headings, total, errors }: Props) {
  const toman = useTenantSettings().currency_display === 'toman';
  const [transferring, setTransferring] = useState(false);

  const form = useForm<{
    from_account_id: number | null;
    to_account_id: number | null;
    amount: number;
    fee: number;
    reference: string;
  }>({ from_account_id: null, to_account_id: null, amount: 0, fee: 0, reference: '' });

  return (
    <AppShell>
      <Head title="خزانه‌داری" />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">خزانه‌داری</h1>
          <p className="text-sm text-muted-foreground">
            جمع موجودی: <Money rial={total.value} withUnit />
          </p>
        </div>

        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link href="/treasury/close">بستن روز</Link>
          </Button>
          <Button onClick={() => setTransferring((open) => !open)}>
            <ArrowLeftRightIcon className="size-4" aria-hidden />
            انتقال بین حساب‌ها
          </Button>
        </div>
      </div>

      {errors.transfer && (
        <p role="alert" className="mt-4 rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {errors.transfer}
        </p>
      )}

      {transferring && (
        <form
          className="mt-4 grid gap-3 rounded-card border border-border p-4 sm:grid-cols-2 lg:grid-cols-5"
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/treasury/transfers', {
              preserveScroll: true,
              onSuccess: () => {
                form.reset();
                setTransferring(false);
              },
            });
          }}
        >
          <div className="space-y-1">
            <Label htmlFor="transfer-from">از</Label>
            <select
              id="transfer-from"
              className="h-8 w-full rounded-lg border border-input bg-transparent px-2.5"
              value={form.data.from_account_id ?? ''}
              onChange={(event) => form.setData('from_account_id', Number(event.target.value))}
            >
              <option value="">انتخاب کنید</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-1">
            <Label htmlFor="transfer-to">به</Label>
            <select
              id="transfer-to"
              className="h-8 w-full rounded-lg border border-input bg-transparent px-2.5"
              value={form.data.to_account_id ?? ''}
              onChange={(event) => form.setData('to_account_id', Number(event.target.value))}
            >
              <option value="">انتخاب کنید</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-1">
            <Label htmlFor="transfer-amount">مبلغ</Label>
            <MoneyField
              id="transfer-amount"
              toman={toman}
              value={form.data.amount}
              onChange={(value) => form.setData('amount', value)}
            />
          </div>

          <div className="space-y-1">
            {/* Not folded into the amount: the destination receives the full sum and the
                fee leaves the source separately, or the balance is wrong by exactly the
                figure nobody can find later. */}
            <Label htmlFor="transfer-fee">کارمزد</Label>
            <MoneyField
              id="transfer-fee"
              toman={toman}
              value={form.data.fee}
              onChange={(value) => form.setData('fee', value)}
            />
          </div>

          <div className="space-y-1">
            <Label htmlFor="transfer-reference">شرح</Label>
            <Input
              id="transfer-reference"
              value={form.data.reference}
              onChange={(event) => form.setData('reference', event.target.value)}
            />
          </div>

          <div className="sm:col-span-2 lg:col-span-5">
            <Button type="submit" disabled={form.processing}>
              ثبت انتقال
            </Button>
          </div>
        </form>
      )}

      <section className="mt-6 space-y-2">
        <h2 className="text-sm font-semibold">حساب‌ها</h2>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {accounts.map((account) => {
            const Icon = ICON[account.type] ?? BanknoteIcon;

            return (
              <Link
                key={account.id}
                href={`/treasury/accounts/${account.id}`}
                className="rounded-card border border-border p-4 transition-colors hover:bg-muted"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="flex items-center gap-2 text-sm font-medium">
                    <Icon className="size-4 text-muted-foreground" aria-hidden />
                    {account.name}
                  </span>
                  <span className="text-2xs text-muted-foreground">{TYPE_LABEL[account.type] ?? account.type}</span>
                </div>

                <p className="mt-3 text-xl font-semibold">
                  <Money rial={account.balance.value} withUnit />
                </p>

                {account.unreconciled.value !== 0 && (
                  <p className="mt-1 text-2xs text-muted-foreground">
                    مغایرت‌گیری‌نشده: <Money rial={account.unreconciled.value} />
                  </p>
                )}
              </Link>
            );
          })}
        </div>
      </section>

      {headings.length > 0 && (
        <section className="mt-8 space-y-2">
          <h2 className="text-sm font-semibold">سرفصل‌ها</h2>
          <p className="text-2xs text-muted-foreground">
            این‌ها جای نگهداری پول نیستند؛ عنوانی هستند که پول زیر آن شمرده می‌شود.
          </p>

          <div className="overflow-x-auto rounded-card border border-border">
            <table className="w-full text-sm">
              <tbody>
                {headings.map((heading) => (
                  <tr key={heading.id} className="border-b border-border last:border-0">
                    <td className="p-3">{heading.name}</td>
                    <td className="p-3 text-2xs text-muted-foreground">
                      {TYPE_LABEL[heading.type] ?? heading.type}
                    </td>
                    <td className="p-3 text-end">
                      <span className="text-2xs text-muted-foreground">جمع </span>
                      <Money rial={heading.total.value} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </AppShell>
  );
}
