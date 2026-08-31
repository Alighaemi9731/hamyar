import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { cn } from '@/lib/utils';

import type { InvoiceItem, InvoiceTotals } from './types';

interface LineItemsProps {
  items: InvoiceItem[];
  totals: InvoiceTotals;
}

/**
 * What was sold.
 *
 * ## Why this is not a `DataTable`
 *
 * `DataTable` is the right answer for a *list of records* — it drops `secondary` columns
 * below `sm` and has no footer. Neither fits an invoice: the columns a shop argues about
 * are the prices, so none of them may disappear on a phone, and a line-item block that
 * does not add up to a total is the gap the reader has to close from memory. This is a
 * financial document, not a list screen, so it is composed here.
 *
 * ## Two layouts, one dataset, no sideways scroll
 *
 * The old page rendered one five-column table at every width. At 375 its min-content
 * width was 467px inside a 467px wrapper, so `overflow-x-auto` never engaged and the
 * **document** scrolled sideways by 110px — an invoice you have to drag left and right to
 * read at the counter.
 *
 * From `sm` up it is a real table with a totalling foot. Below `sm` the same rows render
 * as stacked blocks: description and identity on top, then the arithmetic on one line —
 * quantity × unit price, less any discount, equals the line total. Nothing is dropped and
 * nothing scrolls.
 *
 * ## Latin tabular figures
 *
 * Design-system rule 4: columns must align on their digits, so money here is `latin`
 * whatever the shop's prose setting. The IMEI is `ltr` and ungrouped — it has to be
 * readable back over the phone.
 */
export function LineItems({ items, totals }: LineItemsProps) {
  /*
    The foot sums the column above it, and nothing else.

    It used to print `totals.subtotal` — 88,970,000, the VAT-*exclusive* base — beneath a
    «جمع» column of VAT-*inclusive* line totals adding to 97,701,999. An 8.7-million-toman
    contradiction under a comment claiming "the lines add up". A foot that does not total
    its own column is worse than no foot: it invites the reader to check, and then fails
    the check.

    Integer rial throughout (golden rule 2), so this is exact — and it lands one toman
    under the invoice total, which is precisely what the «گرد کردن» rung in the summary
    exists to explain.
  */
  const lineSum = items.reduce((sum, item) => sum + item.line_total.value, 0);

  return (
    <section aria-labelledby="invoice-items-heading">
      <h2 id="invoice-items-heading" className="mb-4 font-display text-lg font-bold tracking-tight">
        اقلام فاکتور
      </h2>

      {/* The empty state *replaces* the table — it is not appended beneath one
          (design-system rule 10). Rendered additively, a line-less invoice showed column
          headers over an empty body and footed it «جمع اقلام ۰» before saying there was
          nothing there. Not reachable through the application; if it renders, the record
          is incomplete, and the copy says so rather than inviting an action. */}
      {items.length === 0 ? (
        <EmptyState
          title="این فاکتور هیچ قلمی ندارد"
          description="فاکتوری بدون کالا یا خدمت ثبت شده است. اگر این را می‌بینید، سابقه ناقص ذخیره شده."
        />
      ) : (
        <>
          {/* ------------------------------------------------ desktop table -- */}
          <div className="hidden overflow-x-auto rounded-card border border-border sm:block">
            <table className="w-full text-sm">
              <caption className="sr-only">
                اقلام فاکتور با تعداد، قیمت واحد، تخفیف و جمع هر سطر
              </caption>

              <thead className="bg-surface-muted text-2xs text-muted-foreground">
                <tr>
                  <th scope="col" className="p-3 text-start font-medium">
                    شرح
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    تعداد
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    قیمت واحد
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    تخفیف
                  </th>
                  <th scope="col" className="p-3 text-start font-medium">
                    جمع
                  </th>
                </tr>
              </thead>

              <tbody>
                {items.map((item) => (
                  <tr key={item.id} className="border-t border-border align-top">
                    <td className="p-3">
                      <ItemIdentity item={item} />
                    </td>
                    <td className="p-3 text-start tabular">
                      <Num value={item.quantity} variant="table" />
                    </td>
                    <td className="p-3 text-start tabular">
                      <Money rial={item.unit_price.value} digits="latin" />
                    </td>
                    <td className="p-3 text-start tabular text-muted-foreground">
                      {item.discount_amount.value > 0 ? (
                        <Money rial={item.discount_amount.value} digits="latin" />
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="p-3 text-start font-medium tabular">
                      <Money rial={item.line_total.value} digits="latin" />
                    </td>
                  </tr>
                ))}
              </tbody>

              {/* The lines add up. Without this the reader has to take on faith that the
              figures above produced the total in the summary beside them. */}
              <tfoot className="border-t-2 border-border bg-surface-muted/60">
                <tr>
                  <th
                    scope="row"
                    colSpan={4}
                    className="p-3 text-start text-2xs font-medium text-muted-foreground"
                  >
                    جمع سطرها
                  </th>
                  <td className="p-3 text-start font-semibold tabular">
                    <Money rial={lineSum} digits="latin" />
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          {/* ------------------------------------------------- mobile stack -- */}
          <ul className="space-y-3 sm:hidden">
            {items.map((item) => (
              <li key={item.id} className="rounded-card border border-border bg-card p-4">
                <ItemIdentity item={item} />

                <dl className="mt-3 space-y-2 border-t border-border pt-3 text-2xs">
                  <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                    <div className="flex items-baseline gap-1.5">
                      <dt className="text-muted-foreground">تعداد</dt>
                      <dd className="tabular">
                        <Num value={item.quantity} variant="table" />
                      </dd>
                    </div>

                    <div className="flex items-baseline gap-1.5">
                      <dt className="text-muted-foreground">قیمت واحد</dt>
                      <dd className="tabular">
                        <Money rial={item.unit_price.value} digits="latin" />
                      </dd>
                    </div>

                    {item.discount_amount.value > 0 && (
                      <div className="flex items-baseline gap-1.5">
                        <dt className="text-muted-foreground">تخفیف</dt>
                        <dd className="tabular">
                          <Money rial={item.discount_amount.value} digits="latin" />
                        </dd>
                      </div>
                    )}
                  </div>

                  {/* The line's result on its own row. Wrapped into the meta line it landed
                  at the far edge of whichever row it happened to fall on, reading as a
                  fourth fact rather than as the figure the other three produce. */}
                  <div className="flex items-baseline justify-between gap-3 border-t border-border pt-2">
                    <dt className="text-muted-foreground">جمع این قلم</dt>
                    <dd className="text-sm font-semibold tabular">
                      <Money rial={item.line_total.value} digits="latin" />
                    </dd>
                  </div>
                </dl>
              </li>
            ))}

            <li className="flex items-baseline justify-between gap-3 rounded-card border border-border bg-surface-muted px-4 py-3">
              <span className="text-2xs font-medium text-muted-foreground">جمع سطرها</span>
              <span className="font-semibold tabular">
                <Money rial={lineSum} digits="latin" />
              </span>
            </li>
          </ul>
        </>
      )}
    </section>
  );
}

/**
 * The part of a line that says *what this is* — shared by both layouts so a phone and a
 * desktop never disagree about how a handset is identified.
 */
function ItemIdentity({ item }: { item: InvoiceItem }) {
  return (
    <>
      <span className="block font-medium text-pretty">{item.description}</span>

      <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-muted-foreground">
        {item.imei && <Num value={item.imei} variant="ltr" />}

        {item.warranty_months !== null && (
          <span>
            گارانتی <Num value={item.warranty_months} variant="prose" /> ماه
          </span>
        )}

        {/* Returned quantity is a fact about this line that changes what the customer is
            owed, so it carries a tone rather than sitting in the same grey as the IMEI. */}
        {item.returned_quantity > 0 && (
          <span className={cn('font-medium text-warning')}>
            <Num value={item.returned_quantity} variant="prose" /> عدد مرجوع شده
          </span>
        )}
      </span>
    </>
  );
}
