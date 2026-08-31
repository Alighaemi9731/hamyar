import { Head, router } from '@inertiajs/react';
import { CalendarIcon, WalletIcon } from 'lucide-react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { MoneyLadder, MoneyRow } from '@/components/domain/money-ladder';
import { PageHeader } from '@/components/domain/page-header';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface AccountRow {
  id: number;
  name: string;
  type: string;
  opening: MoneyValue;
  movement: MoneyValue;
  closing: MoneyValue;
  unreconciled: MoneyValue;
}

interface Props {
  date: string;
  accounts: AccountRow[];
  totals: { opening: MoneyValue; movement: MoneyValue; closing: MoneyValue };
  pnl: {
    from: string;
    to: string;
    revenue: MoneyValue;
    cost_of_goods: MoneyValue;
    gross_margin: MoneyValue;
    other_income: MoneyValue;
    operating_costs: MoneyValue;
    net_profit: MoneyValue;
    expense_breakdown: Array<{ category: string; amount: MoneyValue }>;
  };
}

/**
 * Closing the day, and what the month has come to.
 *
 * ## The arithmetic is shown, not just the answer
 *
 * Opening + movement = closing, on every row. An operator staring at a drawer that is
 * 400,000 short needs to see where the number entered, and a screen that shows only a
 * closing figure makes them reconstruct the day from memory.
 *
 * ## The P&L rows add up to its own headline
 *
 * Including «سایر» — the costs that reached an expense account without a categorised
 * transaction behind them, like a bank fee on a transfer. Dropping them would make the
 * rows fail to sum to the total, which is how a shop stops believing a report.
 */
/**
 * `numeric` on every money column, which is what gives them a shared right edge in RTL.
 * `secondary` on the two a phone can lose: opening balance and unreconciled are context,
 * where the day's movement and its closing balance are the reason the screen exists.
 */
const accountColumns: Column<AccountRow>[] = [
  {
    key: 'name',
    header: 'حساب',
    cell: (row) => <span className="font-medium">{row.name}</span>,
  },
  {
    key: 'opening',
    header: 'مانده اول روز',
    numeric: true,
    secondary: true,
    cell: (row) => <Money rial={row.opening.value} digits="latin" />,
  },
  {
    key: 'movement',
    header: 'گردش روز',
    numeric: true,
    cell: (row) => <Money rial={row.movement.value} digits="latin" signed />,
  },
  {
    key: 'closing',
    header: 'مانده پایان روز',
    numeric: true,
    cell: (row) => <Money rial={row.closing.value} digits="latin" className="font-medium" />,
  },
  {
    key: 'unreconciled',
    header: 'تأییدنشده',
    numeric: true,
    secondary: true,
    cell: (row) =>
      row.unreconciled.value === 0 ? (
        <span className="text-muted-foreground">—</span>
      ) : (
        <Money rial={row.unreconciled.value} digits="latin" className="text-warning" />
      ),
  },
];

export default function TreasuryClose({ date, accounts, totals, pnl }: Props) {
  return (
    <AppShell
      header={
        <PageHeader
          title="بستن روز"
          back={{ href: '/treasury', label: 'خزانه‌داری' }}
          meta={
            <p className="flex items-center gap-1 text-sm text-muted-foreground">
              <CalendarIcon className="size-4" aria-hidden />
              {formatJalali(date)}
            </p>
          }
        />
      }
    >
      <Head title={`بستن روز ${formatJalali(date)}`} />

      {/*
        A `DataTable`, not a hand-rolled one — and the reason is the alignment, not the
        consistency. Every numeric column here was `text-end`, which in RTL resolves to
        physical *left*: it lines up the most-significant digits and leaves the units
        ragged, which is the one thing a day-close table exists not to do. `DataTable`'s
        `numeric` flag carries the fix and the paragraph explaining it.

        The totals row goes through `footer`, which renders per column, so «جمع» cannot
        drift out from under the figures it totals when somebody reorders the headings.
      */}
      <section className="space-y-4">
        <div>
          <h2 className="font-display text-lg font-bold tracking-tight">حساب‌ها</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            مانده هر حساب در ابتدا و انتهای روز، و آنچه هنوز با صورت‌حساب تطبیق داده نشده.
          </p>
        </div>

        <DataTable
          columns={accountColumns}
          rows={accounts}
          rowKey={(row) => row.id}
          caption="مانده و گردش هر حساب در این روز"
          footer={(column) => {
            if (column.key === 'name') {
              return 'جمع';
            }

            if (column.key === 'opening') {
              return <Money rial={totals.opening.value} digits="latin" />;
            }

            if (column.key === 'movement') {
              return <Money rial={totals.movement.value} digits="latin" />;
            }

            if (column.key === 'closing') {
              return <Money rial={totals.closing.value} withUnit digits="latin" />;
            }

            // Unreconciled has no meaningful total: it is an exposure per account, and a
            // sum of it would read as a figure the shop owes somebody.
            return undefined;
          }}
          empty={
            <EmptyState
              icon={WalletIcon}
              title="حسابی برای بستن نیست"
              description="این روز هیچ حساب فعالی نداشته است."
            />
          }
        />
      </section>

      <section className="mt-8 space-y-2">
        <h2 className="text-sm font-semibold">
          سود و زیان — {formatJalali(pnl.from)} تا {formatJalali(pnl.to)}
        </h2>

        <div className="grid gap-4 lg:grid-cols-2">
          {/*
            A ladder, not a stack of `flex justify-between` rows. Six figures that are
            supposed to add up were each finding their own right edge — the defect measured
            at 99px of scatter on the invoice summary — on the one screen in this product
            whose entire job is arithmetic somebody checks by eye.
          */}
          <MoneyLadder className="rounded-card border border-border p-4 text-sm">
            <MoneyRow label="فروش" rial={pnl.revenue.value} />
            <MoneyRow label="بهای تمام‌شده" rial={pnl.cost_of_goods.value} />
            <MoneyRow label="سود ناخالص" rial={pnl.gross_margin.value} tone="text-foreground" />
            <MoneyRow label="سایر درآمدها" rial={pnl.other_income.value} />
            <MoneyRow label="هزینه‌های عملیاتی" rial={pnl.operating_costs.value} />
            {/* No `withUnit` on a rung. The track is a fixed `9ch`, and «۸٬۶۶۸٬۰۰۰ تومان»
                is 98px of content in it — measured at 375, where it pushed the page 13px
                sideways. The invoice ladder keeps its unit outside the track for the same
                reason; a ladder is one currency read down a column, and repeating the unit
                per rung is redundant as well as too wide. */}
            <MoneyRow
              label="سود خالص"
              rial={pnl.net_profit.value}
              divider
              signed
              tone="text-foreground"
            />
          </MoneyLadder>

          {/*
            A ladder too, and for two reasons beyond alignment.

            It sat beside the profit-and-loss card rendering the *same* figures in a
            different digit system — «۱۲٬۰۰۰» here against `12,000` there, one screen, two
            systems, because this list used the tenant's prose setting and the ladder uses
            the Latin tabular figures design-system rule 4 gives columns.

            And `flex justify-between` gave each expense its own right edge, so a breakdown
            that is supposed to sum to the «هزینه‌های عملیاتی» rung opposite could not be
            read against it.
          */}
          <div className="rounded-card border border-border p-4">
            <h3 className="mb-3 text-sm font-semibold">تفکیک هزینه‌ها</h3>

            {pnl.expense_breakdown.length === 0 ? (
              <p className="text-sm text-muted-foreground">هزینه‌ای در این دوره ثبت نشده است.</p>
            ) : (
              <MoneyLadder className="text-sm">
                {pnl.expense_breakdown.map((row) => (
                  <MoneyRow key={row.category} label={row.category} rial={row.amount.value} />
                ))}
              </MoneyLadder>
            )}
          </div>
        </div>
      </section>
    </AppShell>
  );
}
