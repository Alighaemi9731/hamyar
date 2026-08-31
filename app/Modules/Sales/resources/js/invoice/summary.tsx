import type { ReactNode } from 'react';

import { Money } from '@/components/domain/money';
import { MoneyLadder } from '@/components/domain/money-ladder';
import { Num } from '@/components/domain/num';
import { cn } from '@/lib/utils';

import type { InvoiceCommission, InvoiceProfit, InvoiceTotals } from './types';

/**
 * The ladder itself now lives in `@/components/domain/money-ladder`, with the measurements
 * that justify it — the 99px of scatter, the `9ch` track, the `ps-6` that keeps a rule
 * continuous. It moved because the treasury day-close renders a profit-and-loss ladder with
 * the same requirement, and a correct pattern reachable from one file is a pattern the next
 * screen re-invents wrongly.
 *
 * `VALUE` stays here for the two rungs this card builds by hand — a profit figure whose
 * tone depends on its sign, which `MoneyRow` deliberately does not model.
 */
const VALUE = 'ps-6 text-start tabular';

/**
 * What the sale came to.
 *
 * ## One figure leads, and it is the total
 *
 * The old summary set «مبلغ کل» at 17px/600 — the same step as the rows above it and
 * smaller than the page title. On an invoice, the total is the number the whole document
 * exists to state, so it takes the display step and everything feeding it stays at body
 * size.
 *
 * ## The ladder reconciles, and it only does so because the discount carries its sign
 *
 * Rendered as a bare positive, «تخفیف فاکتور ۱۵۰٬۰۰۰» made the column add to 98,002,000
 * against a stated total of 97,702,000 — 300,000 out, on a document whose entire purpose
 * is to be checkable. It is subtracted, so it is shown subtracted:
 * `88,970,000 − 150,000 + 8,881,999 + 1 = 97,702,000`, exactly.
 *
 * ## A cancelled invoice owes nothing, but it was still paid
 *
 * Voided, the total is muted and struck and the outstanding row is replaced. «پرداخت‌شده»
 * stays: the document column still lists 70,000,000 of receipts, and a rail that deleted
 * the paid figure while announcing that nothing is owed would say nothing about money the
 * shop actually holds and must refund.
 */
export function InvoiceSummary({ totals, isVoid }: { totals: InvoiceTotals; isVoid: boolean }) {
  const owing = totals.outstanding.value > 0;

  return (
    <section
      aria-labelledby="invoice-summary-heading"
      className="rounded-card border border-border bg-card p-5 shadow-low"
    >
      <h2 id="invoice-summary-heading" className="text-sm text-muted-foreground">
        مبلغ فاکتور
      </h2>

      <p
        className={cn(
          'mt-2 font-display text-xl font-bold tracking-tight sm:text-2xl',
          isVoid && 'text-muted-foreground line-through decoration-2'
        )}
      >
        {/* The hook the rest of the product reads this figure by. Preserved exactly. */}
        <span data-testid="invoice-total">
          <Money rial={totals.total.value} withUnit unitPlacement="block" digits="latin" />
        </span>
      </p>

      {/* ONE `<dl>` for the whole card: a second one would derive a second figure track. */}
      <MoneyLadder className="mt-5 border-t border-border pt-4 text-sm">
        {/* «بدون مالیات» is not pedantry. The line-item table's own foot sums that
            column, which is VAT-inclusive; this rung is the VAT-exclusive base the ladder
            builds from. Two different figures need two different names. */}
        <Row label="جمع اقلام (بدون مالیات)" money={totals.subtotal} />
        {totals.discount_amount.value > 0 && (
          <Row label="تخفیف فاکتور" money={{ value: -totals.discount_amount.value }} />
        )}
        {totals.vat_amount.value > 0 && <Row label="مالیات" money={totals.vat_amount} />}
        {totals.shipping_amount.value > 0 && <Row label="ارسال" money={totals.shipping_amount} />}
        {totals.rounding_adjustment.value !== 0 && (
          <Row label="گرد کردن" money={totals.rounding_adjustment} />
        )}

        <Row divider label="پرداخت‌شده" money={totals.paid_total} />

        {!isVoid && (
          <Row
            label={owing ? 'باقی‌مانده' : 'تسویه شده'}
            money={totals.outstanding}
            tone={owing ? 'text-warning' : 'text-success'}
          />
        )}
      </MoneyLadder>

      {isVoid && (
        <p className="mt-3 text-2xs text-muted-foreground">
          این فاکتور ابطال شده و مبلغی بابت آن بدهکار نیست؛ پرداخت‌های بالا باید بازگردانده یا به
          حساب مشتری منظور شود.
        </p>
      )}
    </section>
  );
}

/**
 * Margin on this sale — absent, never zeroed, for staff without `sales.view_profit`.
 *
 * Kept in its own surface rather than folded into the summary: the customer standing at
 * the counter can see this screen, and what the shop earned is not part of the same
 * conversation as what they owe.
 *
 * ## Shown, not hidden, on a cancelled invoice
 *
 * Suppressing it outright was the wrong call and inconsistent with the total two hundred
 * pixels away, which stays and is struck through. Somebody opening a voided invoice is
 * often asking whether a high-margin sale was cancelled — deleting the margin removes the
 * figure they came for. Muted and struck says "this no longer stands" in the same idiom.
 */
export function ProfitPanel({
  profit,
  commission,
  isVoid,
}: {
  profit: InvoiceProfit;
  commission: InvoiceCommission | null;
  isVoid: boolean;
}) {
  return (
    <section
      aria-labelledby="invoice-profit-heading"
      className={cn(
        'rounded-card border border-border bg-card p-5 text-sm',
        isVoid && 'text-muted-foreground'
      )}
    >
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3">
        <h2 id="invoice-profit-heading" className="font-display text-base font-bold">
          سود این فاکتور{isVoid ? ' (ابطال‌شده)' : ''}
        </h2>
        {/* The card states its unit once. Dropping `withUnit` from the rungs — which was
            necessary, because a unit on one rung widened the shared figure track — left
            this card with three amounts and no currency anywhere on it. */}
        <span className="text-2xs text-muted-foreground">تومان</span>
      </div>

      <MoneyLadder className={cn(isVoid && 'line-through decoration-1')}>
        <Row label="فروش (بدون مالیات)" money={profit.revenue} />
        <Row label="بهای تمام‌شده" money={profit.cost} />

        <dt className="border-t border-border pt-2 font-medium">سود</dt>
        <dd
          data-testid="invoice-profit"
          className={cn(VALUE, 'border-t border-border pt-2 font-semibold')}
        >
          <Money rial={profit.profit.value} digits="latin" signed={!isVoid} />
        </dd>

        <dt className="text-2xs text-muted-foreground">حاشیه سود</dt>
        <dd className={cn(VALUE, 'text-2xs text-muted-foreground')}>
          <Num value={profit.margin_percent} variant="table" />٪
        </dd>

        {commission && (
          <>
            <dt className="border-t border-border pt-2 text-2xs text-muted-foreground">
              پورسانت {commission.salesperson ?? 'فروشنده'} (
              <Num value={commission.rate} variant="table" />٪ سود)
            </dt>
            <dd
              data-testid="invoice-commission"
              className={cn(VALUE, 'border-t border-border pt-2 text-2xs')}
            >
              <Money rial={commission.amount.value} digits="latin" />
            </dd>
          </>
        )}
      </MoneyLadder>
    </section>
  );
}

/**
 * One rung of a ladder — a fragment, not a wrapper, so the `<dl>` grid owns both cells
 * and every figure in the block lands in the same column.
 */
function Row({
  label,
  money,
  tone,
  divider = false,
}: {
  label: string;
  money: { value: number };
  tone?: string;
  /** Rules both cells, opening a new group without splitting the list into a second grid. */
  divider?: boolean;
}) {
  const rule = divider ? 'mt-1 border-t border-border pt-3' : undefined;

  return (
    <>
      <dt className={cn('text-muted-foreground', rule, tone && `${tone} font-medium`)}>{label}</dt>
      <dd className={cn(VALUE, rule, tone && `${tone} font-semibold`)}>
        <Money rial={money.value} digits="latin" />
      </dd>
    </>
  );
}

/** A titled block for the page's secondary records — payments, trade-in, returns, notes. */
export function InvoiceSection({
  id,
  title,
  children,
  className,
  headingClassName,
}: {
  /** A stable ASCII id. Deriving one from the Persian title breaks the first time a
   *  title contains a space, and `aria-labelledby` fails silently when it does. */
  id: string;
  title: string;
  children: ReactNode;
  className?: string;
  headingClassName?: string;
}) {
  const headingId = `invoice-section-${id}`;

  return (
    <section aria-labelledby={headingId} className={className}>
      <h2
        id={headingId}
        className={cn('mb-3 font-display text-lg font-bold tracking-tight', headingClassName)}
      >
        {title}
      </h2>
      {children}
    </section>
  );
}
