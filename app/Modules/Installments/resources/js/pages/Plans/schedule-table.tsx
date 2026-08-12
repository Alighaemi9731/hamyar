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
                  <StatusBadge status={row.status === 'pending' ? 'due_soon' : row.status} />
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
