import { Head, Link } from '@inertiajs/react';
import { RotateCcwIcon, SmartphoneIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

import { InvoiceActions } from '../../invoice/invoice-actions';
import { LineItems } from '../../invoice/line-items';
import { InvoiceSection, InvoiceSummary, ProfitPanel } from '../../invoice/summary';
import {
  type Invoice,
  type InvoiceAbilities,
  type InvoiceCommission,
  type InvoiceProfit,
  paymentStatus,
} from '../../invoice/types';
import { VoidPanel } from '../../invoice/void-panel';

interface Props {
  invoice: Invoice;
  profit: InvoiceProfit | null;
  /**
   * Absent for anyone without `sales.view_profit` — including the salesperson who
   * earned it. Commission is a known percentage of margin, so showing it shows the
   * margin (Gate 1).
   */
  commission: InvoiceCommission | null;
  party_balance: MoneyValue | null;
  can: InvoiceAbilities;
}

/**
 * One sale, after the fact.
 *
 * The page a shop opens when a customer comes back — to reprint, to return, to argue
 * about what was charged. So every figure that produced the total is on it, including
 * the rounding adjustment, which exists precisely so that nobody has to explain a gap
 * between the lines and the total from memory.
 *
 * ## Four zones, in the order the counter asks for them
 *
 * **Identity** — which invoice, is it still valid, whose, when. Previously this lived at
 * the end of the reading order inside a stack of four identically-weighted panels, so
 * the first thing a reader met was the line items of an invoice they had not yet
 * identified. It is now a band across the top.
 *
 * **Document** — the lines, and what was paid against them. The lines total in a foot,
 * so the jump to the summary beside them is arithmetic the reader can follow.
 *
 * **Summary** — one dominant figure. The total used to render at 17px, smaller than the
 * page title and level with the rows feeding it.
 *
 * **Danger** — void, alone at the foot in its own region. See `VoidPanel`.
 *
 * ## A void invoice says so before anything else
 *
 * Not one tinted paragraph among many. The band takes the danger tone, the badge says
 * «ابطال‌شده», and the reason and date are stated where the customer's name would be —
 * because on a cancelled invoice, *why* is the fact somebody came to find.
 *
 * Profit is absent, not zeroed, for staff without `sales.view_profit`.
 */
export default function InvoiceShow({
  invoice,
  profit,
  commission,
  party_balance: partyBalance,
  can,
}: Props) {
  const isFinal = invoice.status === 'final';
  const isVoid = invoice.status === 'void';

  return (
    <AppShell
      title={invoice.number ? `فاکتور ${invoice.number}` : 'پیش‌نویس فاکتور'}
      actions={<InvoiceActions invoice={invoice} can={can} />}
    >
      <Head title={invoice.number ? `فاکتور ${invoice.number}` : 'فاکتور'} />

      <div className="space-y-8">
        <IdentityBand invoice={invoice} partyBalance={partyBalance} />

        {/*
          Two columns from `xl`, not `lg`. At 1024 the sidebar appears in the same
          breakpoint that used to split this grid, leaving 640px of content — and a fixed
          22rem rail took 352px of it, so the *document* column came out at 288px, narrower
          than its own summary. An invoice whose line items are thinner than the box
          totalling them is the wrong way round. Measured, then moved.
        */}
        <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] xl:items-start">
          {/* The document. In RTL the first grid column is the reading-start one, so the
              lines sit where the eye lands and the money ladder sits beside them. */}
          <div className="space-y-8">
            <LineItems items={invoice.items} totals={invoice.totals} />

            <InvoiceSection id="payments" title="پرداخت‌ها">
              {invoice.payments.length === 0 ? (
                <p className="rounded-card border border-dashed border-border px-4 py-4 text-sm text-muted-foreground">
                  پرداختی ثبت نشده — کل مبلغ به حساب مشتری بدهکار شده است.
                </p>
              ) : (
                <ul className="space-y-2">
                  {invoice.payments.map((payment) => (
                    <li
                      key={payment.id}
                      className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-card border border-border bg-card px-4 py-3 text-sm"
                    >
                      <span className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-medium">{payment.method_label}</span>
                        {payment.account_name && (
                          <span className="text-2xs text-muted-foreground">
                            {payment.account_name}
                          </span>
                        )}
                        {payment.reference && (
                          <Num
                            value={payment.reference}
                            variant="ltr"
                            className="text-2xs text-muted-foreground"
                          />
                        )}
                      </span>

                      <span className="flex flex-wrap items-baseline gap-x-3">
                        {payment.tendered_amount && (
                          <span className="text-2xs text-muted-foreground">
                            دریافتی <Money rial={payment.tendered_amount.value} digits="latin" /> —
                            باقی‌مانده <Money rial={payment.change.value} digits="latin" />
                          </span>
                        )}
                        <Money
                          rial={payment.amount.value}
                          digits="latin"
                          className="font-medium tabular"
                        />
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </InvoiceSection>

            {invoice.trade_in && (
              <InvoiceSection id="trade-in" title="معاوضه">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-card border border-border bg-card px-4 py-3 text-sm">
                  <span className="flex flex-wrap items-baseline gap-x-2">
                    <SmartphoneIcon
                      className="size-4 shrink-0 self-center text-muted-foreground"
                      aria-hidden
                    />
                    <span className="font-medium">{invoice.trade_in.device_name}</span>
                    {invoice.trade_in.imei1 && (
                      <Num
                        value={invoice.trade_in.imei1}
                        variant="ltr"
                        className="text-2xs text-muted-foreground"
                      />
                    )}
                    {invoice.trade_in.grade && (
                      <span className="text-2xs text-muted-foreground">
                        درجه {invoice.trade_in.grade}
                      </span>
                    )}
                  </span>

                  <Money
                    rial={invoice.trade_in.agreed_price.value}
                    withUnit
                    digits="latin"
                    className="font-medium tabular"
                  />
                </div>
              </InvoiceSection>
            )}

            {invoice.returns.length > 0 && (
              <InvoiceSection id="returns" title="برگشت‌ها">
                <ul className="space-y-2">
                  {invoice.returns.map((salesReturn) => (
                    <li
                      key={salesReturn.id}
                      className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-card border border-warning/25 bg-warning/5 px-4 py-3 text-sm"
                    >
                      <span className="flex flex-wrap items-baseline gap-x-2">
                        <RotateCcwIcon
                          className="size-4 shrink-0 self-center text-warning"
                          aria-hidden
                        />
                        <span className="font-medium tabular">{salesReturn.number}</span>
                        <span className="text-2xs text-muted-foreground">
                          {formatJalali(salesReturn.returned_at)}
                          {salesReturn.reason ? ` — ${salesReturn.reason}` : ''}
                        </span>
                      </span>

                      <Money
                        rial={salesReturn.total.value}
                        digits="latin"
                        className="font-medium tabular"
                      />
                    </li>
                  ))}
                </ul>
              </InvoiceSection>
            )}
          </div>

          {/* The money ladder, and — only for those permitted — what the sale earned. */}
          <aside className="w-full max-w-md space-y-4 xl:max-w-none xl:sticky xl:top-24">
            <InvoiceSummary totals={invoice.totals} isVoid={isVoid} />

            {/* Shown on a void invoice too, muted and struck — the same idiom the total
                beside it uses. Suppressing it removed the figure a manager opens a
                cancelled invoice to find. */}
            {profit && <ProfitPanel profit={profit} commission={commission} isVoid={isVoid} />}

            {invoice.notes && (
              /* Through `InvoiceSection` like every other block. Hand-rolled, this was the
                 only section on the page with no accessible name — its heading carried no
                 id and the section no `aria-labelledby`, so it enumerated as a bare
                 landmark while every sibling announced itself. */
              <InvoiceSection
                id="notes"
                title="توضیحات"
                className="rounded-card border border-border bg-card p-5 text-sm"
                headingClassName="mb-1.5 text-base"
              >
                <p className="text-pretty text-muted-foreground">{invoice.notes}</p>
              </InvoiceSection>
            )}
          </aside>
        </div>

        {/* Destructive actions sit alone, after everything the operator came to read. */}
        {isFinal && can.void && <VoidPanel invoice={invoice} />}
      </div>
    </AppShell>
  );
}

/* --------------------------------------------------------------- identity -- */

/**
 * Which invoice this is, whether it still stands, and whose it is.
 *
 * One band rather than a panel in a stack of four: these are the facts a reader needs
 * before the line items mean anything, and they used to arrive last.
 */
function IdentityBand({
  invoice,
  partyBalance,
}: {
  invoice: Invoice;
  partyBalance: MoneyValue | null;
}) {
  const isVoid = invoice.status === 'void';

  return (
    <section
      aria-labelledby="invoice-identity-heading"
      className={cn(
        'rounded-card border p-5 shadow-low sm:p-6',
        isVoid ? 'border-danger/30 bg-danger/5' : 'border-border bg-card'
      )}
    >
      <h2 id="invoice-identity-heading" className="sr-only">
        مشخصات فاکتور
      </h2>

      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <StatusBadge status={invoice.status} label={invoice.status_label} />

        {/* Payment standing is a second, independent fact: a final invoice can be paid,
            part-paid or unpaid, and «نهایی» says nothing about which. Not shown on a
            void invoice, where what is owed has stopped being the question. */}
        {!isVoid && <StatusBadge status={paymentStatus(invoice.totals)} />}

        <span className="text-2xs text-muted-foreground">
          {invoice.issued_at ? formatJalali(invoice.issued_at, { longMonth: true }) : 'ثبت‌نشده'}
        </span>
      </div>

      {isVoid && (
        <p className="mt-4 text-sm text-danger">
          این فاکتور در {formatJalali(invoice.voided_at)} ابطال شد
          {invoice.void_reason ? ` — ${invoice.void_reason}` : ''}.
        </p>
      )}

      <dl className="mt-5 grid gap-x-8 gap-y-4 border-t border-border pt-5 sm:grid-cols-2 lg:grid-cols-4">
        <Fact label="مشتری">
          {invoice.party ? (
            <>
              <Link
                href={`/crm/parties/${invoice.party.id}`}
                className="inline-flex min-h-10 items-center font-medium text-primary hover:underline"
              >
                {invoice.party.name}
              </Link>
              {invoice.party.mobile && (
                /* The wrapper is the block, not the number. `Num variant="ltr"` sets
                   `dir="ltr"` on its own span, and a *block* in LTR aligns its text to
                   the left — so the phone number drifted to the opposite edge of the
                   card from the name it belongs to. Keeping the isolation inline and
                   letting an RTL block own the line puts it back under the name. */
                <span className="mt-0.5 block">
                  <Num
                    value={invoice.party.mobile}
                    variant="ltr"
                    className="text-2xs text-muted-foreground"
                  />
                </span>
              )}
            </>
          ) : (
            <span className="text-muted-foreground">مشتری گذری</span>
          )}
        </Fact>

        {partyBalance && (
          <Fact label="مانده حساب مشتری">
            {/* بدهکار/بستانکار rather than a sign: a minus needs the reader to remember
                which direction is which, and these two words are what an Iranian
                bookkeeper already reads on a statement. */}
            {partyBalance.value === 0 ? (
              <span className="text-muted-foreground">تسویه</span>
            ) : (
              <span className={cn('font-medium', partyBalance.value > 0 && 'text-warning')}>
                {partyBalance.value > 0 ? 'بدهکار ' : 'بستانکار '}
                <Money rial={Math.abs(partyBalance.value)} digits="latin" />
              </span>
            )}
          </Fact>
        )}

        <Fact label="شعبه">{invoice.branch_name}</Fact>

        {invoice.salesperson_name && <Fact label="فروشنده">{invoice.salesperson_name}</Fact>}
      </dl>
    </section>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0 space-y-1">
      <dt className="text-2xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}
