import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

import { type InstallmentPlanPayload, ScheduleTable } from '../../installments/schedule-table';

interface Props {
  plan: InstallmentPlanPayload;
}

/**
 * One instalment contract, after it is signed.
 *
 * The schedule is the page. Everything else — who signed, who guaranteed, what the
 * profit came to — is context for the rows a shop is going to chase for the next six
 * months.
 */
export default function InstallmentPlanShow({ plan }: Props) {
  return (
    <AppShell
      title={`قرارداد اقساطی ${plan.number}`}
      actions={
        <Button asChild variant="outline">
          <a href={`/installments/plans/${plan.id}/print`} target="_blank" rel="noreferrer">
            <PrinterIcon className="size-4" aria-hidden />
            چاپ قرارداد
          </a>
        </Button>
      }
    >
      <Head title={`قرارداد ${plan.number}`} />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <ScheduleTable plan={plan} />

        <aside className="space-y-5">
          <dl className="space-y-2 rounded-card border border-border p-4 text-sm">
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">وضعیت</dt>
              <dd>
                <StatusBadge status={plan.status} />
              </dd>
            </div>
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">مشتری</dt>
              <dd>
                <Link
                  href={`/crm/parties/${plan.party.id}`}
                  className="text-primary hover:underline"
                >
                  {plan.party.name}
                </Link>
              </dd>
            </div>
            {plan.guarantor && (
              <div className="flex items-baseline justify-between">
                <dt className="text-muted-foreground">ضامن</dt>
                <dd>
                  <Link
                    href={`/crm/parties/${plan.guarantor.id}`}
                    className="text-primary hover:underline"
                  >
                    {plan.guarantor.name}
                  </Link>
                </dd>
              </div>
            )}
            {plan.invoice && (
              <div className="flex items-baseline justify-between">
                <dt className="text-muted-foreground">فاکتور</dt>
                <dd>
                  <Link
                    href={`/sales/invoices/${plan.invoice.id}`}
                    className="tabular text-primary hover:underline"
                  >
                    {plan.invoice.number}
                  </Link>
                </dd>
              </div>
            )}
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">سررسید اولین قسط</dt>
              <dd className="tabular">{formatJalali(plan.first_due_at)}</dd>
            </div>
          </dl>

          <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">پیش‌پرداخت</dt>
              <dd>
                <Money rial={plan.down_payment.value} digits="latin" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">اصل تقسیط‌شده</dt>
              <dd>
                <Money rial={plan.principal.value} digits="latin" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">
                سود (<Num value={plan.profit_percent} variant="prose" />
                ٪)
              </dt>
              <dd>
                <Money rial={plan.profit_amount.value} digits="latin" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between border-t border-border pt-2 font-semibold">
              <dt>مجموع اقساط</dt>
              <dd data-testid="plan-total-payable">
                <Money rial={plan.total_payable.value} digits="latin" withUnit />
              </dd>
            </div>
          </dl>

          {plan.notes && (
            <div className="rounded-card border border-border p-4 text-sm">
              <h2 className="mb-1 text-sm font-semibold">توضیحات</h2>
              <p className="text-muted-foreground">{plan.notes}</p>
            </div>
          )}
        </aside>
      </div>
    </AppShell>
  );
}
