import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
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

interface PlanLimit {
  key: string;
  label: string;
  unit: string;
  window: 'month' | 'total';
  /** null = unlimited on this plan. */
  value: number | null;
}

interface PlanCard {
  code: string;
  name: string;
  tagline: string | null;
  price: MoneyValue;
  limits: PlanLimit[];
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
  /** The plan a quota block sent this shop here to buy, if it named a real public one. */
  highlight: string | null;
  /** The screen to put them back on afterwards; already sanitised server-side. */
  return_to: string | null;
}

/**
 * Plan selection and invoice history.
 *
 * The proration figure on each card is computed server-side by the same
 * `ProrationCalculator` that writes the invoice, so what the shop is quoted here and
 * what it is charged cannot disagree (ADR 0006).
 */
export default function Billing({
  subscription,
  plans,
  invoices,
  highlight,
  return_to,
}: BillingProps) {
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
                    : subscription.current_period_end
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

        {/*
          Two up from `md`, three only from `xl` — and the jump is not where it looks like
          it should be, for the reason the treasury summary records: **the sidebar appears
          at `lg`**, so the content column is narrower at 1024 than at 768. Three tracks at
          `lg` are 208px each, and «۱٬۱۹۰٬۰۰۰» at 40px is 271px, so the row overflowed the
          page at the width that looked safest.

          Measured: 768 → 340px per track, 1024 → 324px, 1280 → 293px. The figure fits at
          all three.
        */}
        <div className="mt-6 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {plans.map((plan) => (
            <article
              key={plan.code}
              className={cn(
                // `min-w-0` is load-bearing. A grid track is `minmax(auto, max-content)`
                // by default, and `auto`'s floor is *min-content* — so a card whose
                // contents will not compress below 328px made the track 376px wide inside
                // a 375px viewport and pushed the whole page sideways. Measured at 375,
                // 768 and 1280; the page overflowed at every width it was looked at.
                'flex min-w-0 flex-col rounded-card border bg-card p-6',
                plan.is_current && 'border-brand ring-1 ring-brand',
                // The rung a quota block sent them here to buy. Marked more strongly than
                // the current plan, because a shop arriving from a block is not comparing
                // three cards — it has already been told which one clears the wall it hit,
                // and the job of this page is to make that one button obvious.
                !plan.is_current && plan.code === highlight && 'border-warning ring-2 ring-warning',
                !plan.is_current && plan.code !== highlight && 'border-border'
              )}
            >
              {!plan.is_current && plan.code === highlight ? (
                <p className="mb-3 -mt-1 text-xs font-medium text-warning">
                  این پلن سهمیهٔ لازم برای ادامهٔ کارتان را دارد
                </p>
              ) : null}

              <h3 className="text-lg font-semibold">{plan.name}</h3>
              {plan.tagline ? (
                <p className="mt-1 text-sm text-muted-foreground">{plan.tagline}</p>
              ) : null}

              {/*
                Two things were making this overflow its own column, and both were
                measured rather than guessed: at 768 the price ran 326px inside a ~220px
                track, pushing the page 100px sideways.

                `text-3xl` is 56px — the hero step, which the type scale reserves for a
                landing headline, not for one of three cards in a row. 40px is the step a
                page's anchor figure takes, and this is that.

                And `<Money>` is `whitespace-nowrap` on purpose: a nine-digit figure and
                its unit have no break opportunity between them. `unitPlacement="block"`
                is the documented answer — the component's own docblock names this exact
                case, "a nine-digit figure plus its unit does not fit a quarter-width
                card". «ماهانه» goes to its own line with it, being a qualifier rather
                than part of the figure.
              */}
              <p className="mt-4 font-display text-2xl font-bold tracking-tight">
                {plan.price.value === 0 ? (
                  'رایگان'
                ) : (
                  <Money rial={plan.price.value} withUnit unitPlacement="block" />
                )}
              </p>

              {plan.price.value > 0 && <p className="mt-1 text-sm text-muted-foreground">ماهانه</p>}

              {/* Monthly credits, not a module checklist: every module is open on every
                  plan, so a list of ticks would be identical on all three cards and tell
                  a shopkeeper nothing about what they are choosing between. */}
              <ul className="mt-5 grow space-y-2 text-sm">
                {plan.limits.slice(0, 6).map((limit) => (
                  <li key={limit.key} className="flex items-center gap-2">
                    <CheckIcon className="size-4 shrink-0 text-success" aria-hidden />
                    <span>
                      {limit.value === null ? (
                        <span className="font-semibold">نامحدود</span>
                      ) : (
                        <Num value={limit.value} className="font-semibold" />
                      )}{' '}
                      {limit.label}
                      <span className="text-muted-foreground">
                        {limit.window === 'month' ? ' در ماه' : ' (ظرفیت)'}
                      </span>
                    </span>
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
                    {/* Bound by `Plan::getRouteKeyName()` = code since Phase 12.1. The
                        route was id-bound before that, so this button 404'd for every
                        shop that ever pressed it. */}
                    <Button
                      className="w-full"
                      onClick={() =>
                        router.post(`/billing/subscribe/${plan.code}`, {
                          // Carried through from the block that sent them here, so the
                          // round trip ends where it started rather than on a receipt.
                          ...(return_to ? { return_to } : {}),
                        })
                      }
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
                            پرداخت امروز: <Money rial={plan.change.amount_due.value} withUnit />
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
