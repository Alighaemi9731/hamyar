import { Head, router } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
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

      <section className="mt-6 space-y-2">
        <h2 className="text-sm font-semibold">حساب‌ها</h2>

        <div className="overflow-x-auto rounded-card border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-2xs text-muted-foreground">
                <th className="p-3 text-start font-medium">حساب</th>
                <th className="p-3 text-end font-medium">مانده اول روز</th>
                <th className="p-3 text-end font-medium">گردش روز</th>
                <th className="p-3 text-end font-medium">مانده پایان روز</th>
                <th className="p-3 text-end font-medium">تأییدنشده</th>
              </tr>
            </thead>
            <tbody>
              {accounts.map((row) => (
                <tr key={row.id} className="border-b border-border last:border-0">
                  <td className="p-3">{row.name}</td>
                  <td className="p-3 text-end tabular">
                    <Money rial={row.opening.value} />
                  </td>
                  <td className="p-3 text-end tabular">
                    <Money rial={row.movement.value} />
                  </td>
                  <td className="p-3 text-end font-medium tabular">
                    <Money rial={row.closing.value} />
                  </td>
                  <td className="p-3 text-end tabular text-2xs text-muted-foreground">
                    <Money rial={row.unreconciled.value} />
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="bg-muted/50 font-semibold">
                <td className="p-3">جمع</td>
                <td className="p-3 text-end tabular">
                  <Money rial={totals.opening.value} />
                </td>
                <td className="p-3 text-end tabular">
                  <Money rial={totals.movement.value} />
                </td>
                <td className="p-3 text-end tabular">
                  <Money rial={totals.closing.value} withUnit />
                </td>
                <td />
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <section className="mt-8 space-y-2">
        <h2 className="text-sm font-semibold">
          سود و زیان — {formatJalali(pnl.from)} تا {formatJalali(pnl.to)}
        </h2>

        <div className="grid gap-4 lg:grid-cols-2">
          <dl className="space-y-2 rounded-card border border-border p-4 text-sm">
            <Row label="فروش">
              <Money rial={pnl.revenue.value} />
            </Row>
            <Row label="بهای تمام‌شده">
              <Money rial={pnl.cost_of_goods.value} />
            </Row>
            <Row label="سود ناخالص" strong>
              <Money rial={pnl.gross_margin.value} />
            </Row>
            <Row label="سایر درآمدها">
              <Money rial={pnl.other_income.value} />
            </Row>
            <Row label="هزینه‌های عملیاتی">
              <Money rial={pnl.operating_costs.value} />
            </Row>
            <div className="border-t border-border pt-2">
              <Row label="سود خالص" strong>
                <Money rial={pnl.net_profit.value} withUnit />
              </Row>
            </div>
          </dl>

          <div className="space-y-2 rounded-card border border-border p-4">
            <h3 className="text-sm font-semibold">تفکیک هزینه‌ها</h3>

            {pnl.expense_breakdown.length === 0 ? (
              <p className="text-sm text-muted-foreground">هزینه‌ای در این دوره ثبت نشده است.</p>
            ) : (
              <ul className="space-y-1 text-sm">
                {pnl.expense_breakdown.map((row) => (
                  <li key={row.category} className="flex items-baseline justify-between gap-2">
                    <span>{row.category}</span>
                    <span className="tabular">
                      <Money rial={row.amount.value} />
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </section>
    </AppShell>
  );
}

function Row({
  label,
  children,
  strong,
}: {
  label: string;
  children: React.ReactNode;
  strong?: boolean;
}) {
  return (
    <div className={`flex items-baseline justify-between gap-2 ${strong ? 'font-semibold' : ''}`}>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular">{children}</dd>
    </div>
  );
}
