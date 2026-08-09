import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { SharedProps } from '@/types';
import { cn } from '@/lib/utils';

interface MoneyValue {
  value: number;
  formatted: string;
}

interface PlanChange {
  kind: 'upgrade' | 'downgrade' | 'same';
  amount_due: MoneyValue;
  effective_at: 'immediately' | 'period_end';
}

interface PlanCard {
  code: string;
  name: string;
  tagline: string | null;
  price: MoneyValue;
  modules: string[];
  is_current: boolean;
  change: PlanChange | null;
}

interface InvoiceRow {
  id: number;
  number: string;
  status: string;
  total: MoneyValue;
  paid_at: string | null;
  created_at: string | null;
}

interface BillingProps {
  subscription: {
    plan_code: string;
    plan_name: string;
    status: string;
    is_trialing: boolean;
    trial_ends_at: string | null;
    current_period_end: string | null;
    credit_balance: MoneyValue;
  } | null;
  plans: PlanCard[];
  invoices: InvoiceRow[];
}

/**
 * Plan selection and invoice history.
 *
 * The proration figure on each card is computed server-side by the same
 * `ProrationCalculator` that writes the invoice, so what the shop is quoted here and
 * what it is charged cannot disagree (ADR 0006).
 */
export default function Billing({ subscription, plans, invoices }: BillingProps) {
  const { flash } = usePage<SharedProps>().props;

  return (
    <AppShell title="اشتراک و صورتحساب">
      <Head title="اشتراک و صورتحساب" />

      {flash?.success ? (
        <p className="mb-6 rounded-card border border-success/25 bg-success/10 px-4 py-3 text-sm text-success">
          {flash.success}
        </p>
      ) : null}

      {flash?.error ? (
        <p className="mb-6 rounded-card border border-danger/25 bg-danger/10 px-4 py-3 text-sm text-danger">
          {flash.error}
        </p>
      ) : null}

      {subscription ? (
        <section className="mb-12 rounded-card border border-border bg-card p-6">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-sm text-muted-foreground">پلن فعلی</p>
              <p className="mt-1 text-2xl font-semibold">{subscription.plan_name}</p>
            </div>

            <StatusBadge status={subscription.status} />
          </div>

          <Separator className="my-5" />

          <dl className="grid gap-5 sm:grid-cols-3">
            <div>
              <dt className="text-sm text-muted-foreground">
                {subscription.is_trialing ? 'پایان دوره آزمایشی' : 'تمدید بعدی'}
              </dt>
              <dd className="mt-1 font-medium">
                {formatJalali(
                  subscription.is_trialing
                    ? subscription.trial_ends_at
                    : subscription.current_period_end,
                ) || '—'}
              </dd>
            </div>

            <div>
              <dt className="text-sm text-muted-foreground">اعتبار قابل استفاده</dt>
              <dd className="mt-1 font-medium">
                <Money rial={subscription.credit_balance.value} withUnit />
              </dd>
            </div>
          </dl>
        </section>
      ) : null}

      <section aria-labelledby="plans-heading">
        <h2 id="plans-heading" className="text-xl font-semibold">
          پلن‌ها
        </h2>

        <div className="mt-6 grid gap-6 md:grid-cols-3">
          {plans.map((plan) => (
            <article
              key={plan.code}
              className={cn(
                'flex flex-col rounded-card border bg-card p-6',
                plan.is_current ? 'border-brand ring-1 ring-brand' : 'border-border',
              )}
            >
              <h3 className="text-lg font-semibold">{plan.name}</h3>
              {plan.tagline ? (
                <p className="mt-1 text-sm text-muted-foreground">{plan.tagline}</p>
              ) : null}

              <p className="mt-4 text-3xl font-semibold tracking-tight">
                <Money rial={plan.price.value} withUnit />
                <span className="text-base font-normal text-muted-foreground"> / ماهانه</span>
              </p>

              <ul className="mt-5 grow space-y-2 text-sm">
                {plan.modules.map((module) => (
                  <li key={module} className="flex items-center gap-2">
                    <CheckIcon className="size-4 shrink-0 text-success" aria-hidden />
                    <span>{module}</span>
                  </li>
                ))}
              </ul>

              <div className="mt-6">
                {plan.is_current ? (
                  <Button disabled className="w-full" variant="secondary">
                    پلن فعلی شما
                  </Button>
                ) : (
                  <>
                    <Button
                      className="w-full"
                      onClick={() => router.post(`/billing/subscribe/${plan.code}`)}
                    >
                      {plan.change?.kind === 'downgrade' ? 'تغییر به این پلن' : 'ارتقا به این پلن'}
                    </Button>

                    {plan.change ? (
                      <p className="mt-3 text-center text-xs text-muted-foreground">
                        {plan.change.effective_at === 'period_end' ? (
                          // The single most-asked billing question, answered before it
                          // is asked: a downgrade costs nothing today.
                          <>از ابتدای دوره بعد اعمال می‌شود و امروز مبلغی دریافت نمی‌شود.</>
                        ) : (
                          <>
                            پرداخت امروز:{' '}
                            <Money rial={plan.change.amount_due.value} withUnit />
                          </>
                        )}
                      </p>
                    ) : null}
                  </>
                )}
              </div>
            </article>
          ))}
        </div>
      </section>

      {invoices.length > 0 ? (
        <section aria-labelledby="invoices-heading" className="mt-14">
          <h2 id="invoices-heading" className="text-xl font-semibold">
            صورتحساب‌ها
          </h2>

          <div className="mt-5 overflow-x-auto rounded-card border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>شماره</TableHead>
                  <TableHead>تاریخ</TableHead>
                  <TableHead>مبلغ</TableHead>
                  <TableHead>وضعیت</TableHead>
                  <TableHead className="text-end">‌</TableHead>
                </TableRow>
              </TableHeader>

              <TableBody>
                {invoices.map((invoice) => (
                  <TableRow key={invoice.id}>
                    <TableCell className="tabular">{invoice.number}</TableCell>
                    <TableCell>{formatJalali(invoice.created_at) || '—'}</TableCell>
                    <TableCell>
                      <Money rial={invoice.total.value} digits="latin" />
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={invoice.status} />
                    </TableCell>
                    <TableCell className="text-end">
                      <Link
                        href={`/billing/invoices/${invoice.id}`}
                        className="text-sm text-brand hover:underline"
                      >
                        مشاهده
                      </Link>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </section>
      ) : null}
    </AppShell>
  );
}
