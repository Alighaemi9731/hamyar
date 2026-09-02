import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

export interface InstallmentPlanPayload {
  id: number;
  number: string;
  status: string;
  party: { id: number; name: string };
  guarantor: { id: number; name: string } | null;
  invoice: { id: number; number: string | null } | null;
  down_payment: MoneyValue;
  principal: MoneyValue;
  profit_percent: number;
  profit_amount: MoneyValue;
  total_payable: MoneyValue;
  contract_total: MoneyValue;
  installment_count: number;
  interval_months: number;
  first_due_at: string;
  notes: string | null;
  rows: Array<{
    id: number;
    sequence: number;
    due_at: string;
    amount: MoneyValue;
    status: string;
  }>;
}

/** How close a due date has to be before a shop starts chasing it. */
const DUE_SOON_DAYS = 7;

/**
 * What to badge an unpaid instalment.
 *
 * The stored status is just `pending` — the row carries no opinion about the calendar,
 * and it should not: "overdue" is a fact about today, not about the row, and baking it
 * into the record would need a nightly job to keep true.
 *
 * So the reading is derived here, from the due date:
 *
 * - past its date → **معوق**, the one a shop acts on
 * - within a week → **نزدیک سررسید**
 * - anything further out → **در انتظار پرداخت**
 *
 * The previous version mapped every `pending` row to «نزدیک سررسید» unconditionally,
 * which badged an instalment due in six months as due soon. A contract where every line
 * is urgent is a contract where no line is.
 */
function readingFor(status: string, dueAt: string): string {
  if (status !== 'pending') {
    return status;
  }

  const due = new Date(dueAt);

  if (Number.isNaN(due.getTime())) {
    return 'pending';
  }

  // Compared date-to-date, not instant-to-instant: an instalment due today is due
  // today all day, not overdue from one second past midnight.
  const startOfDueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate());
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());

  const days = Math.round(
    (startOfDueDay.getTime() - startOfToday.getTime()) / (1000 * 60 * 60 * 24)
  );

  if (days < 0) {
    return 'overdue';
  }

  return days <= DUE_SOON_DAYS ? 'due_soon' : 'pending';
}

/**
 * The schedule, shared by the screen and the printed contract.
 *
 * One component rather than two, because the whole point of a contract is that the paper
 * and the screen say the same thing. A second table built for print is a second table
 * that drifts, and the customer is holding the one that is wrong.
 *
 * `withStatus` is off for the contract: on the day it is signed every row is pending, and
 * a column of «در انتظار» on a printed page is noise.
 */
export function ScheduleTable({
  plan,
  withStatus = true,
}: {
  plan: InstallmentPlanPayload;
  withStatus?: boolean;
}) {
  return (
    <div className="overflow-x-auto rounded-card border border-border print:rounded-none print:border-black/30">
      <table className="w-full text-sm">
        {/* `sr-only`, because this table also prints: a screen-reader caption is clipped to 1px and puts nothing on paper. */}
        <caption className="sr-only">
          جدول اقساط: شماره، سررسید، مبلغ، وصول‌شده و وضعیت هر قسط.
        </caption>
        <thead className="bg-muted/50 text-2xs text-muted-foreground print:bg-transparent print:text-black">
          <tr>
            <th scope="col" className="p-3 text-start font-medium">
              قسط
            </th>
            <th scope="col" className="p-3 text-start font-medium">
              سررسید
            </th>
            <th scope="col" className="p-3 text-end font-medium">
              مبلغ
            </th>
            {withStatus && (
              <th scope="col" className="p-3 text-start font-medium">
                وضعیت
              </th>
            )}
          </tr>
        </thead>

        <tbody>
          {plan.rows.map((row) => (
            <tr key={row.id} className="border-t border-border print:border-black/20">
              <td className="p-3">
                <Num value={row.sequence} variant="table" />
              </td>
              <td className="p-3 tabular">{formatJalali(row.due_at)}</td>
              <td className="p-3 text-end">
                <Money rial={row.amount.value} digits="latin" />
              </td>
              {withStatus && (
                <td className="p-3">
                  <StatusBadge status={readingFor(row.status, row.due_at)} />
                </td>
              )}
            </tr>
          ))}
        </tbody>

        <tfoot>
          <tr className="border-t-2 border-border font-semibold print:border-black/40">
            <td className="p-3" colSpan={2}>
              مجموع <Num value={plan.installment_count} variant="prose" /> قسط
            </td>
            <td className="p-3 text-end" data-testid="schedule-sum">
              {/* Summed from the rows rather than printing the stored total: if the two
                  ever disagree, the contract shows the disagreement instead of hiding
                  it behind a number nobody can check. */}
              <Money
                rial={plan.rows.reduce((sum, row) => sum + row.amount.value, 0)}
                digits="latin"
              />
            </td>
            {withStatus && <td />}
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
