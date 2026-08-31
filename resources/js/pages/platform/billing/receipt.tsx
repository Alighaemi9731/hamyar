import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

interface MoneyValue {
  value: number;
  formatted: string;
}

interface ReceiptProps {
  invoice: {
    number: string;
    status: string;
    subtotal: MoneyValue;
    discount: MoneyValue;
    credit_applied: MoneyValue;
    total: MoneyValue;
    paid_at: string | null;
    lines: { label: string; amount: MoneyValue }[];
    reference: string | null;
  };
  [key: string]: unknown;
}

/**
 * The proof a shop keeps.
 *
 * ## It is a document, so it goes through `PrintLayout`
 *
 * This was the only printable surface in the product that did not. It hand-rolled
 * `print:hidden` and a bare `window.print()`, which cost it two things the system already
 * solves:
 *
 * - **No `@page`.** The receipt printed on whatever paper the browser defaulted to, at
 *   whatever margin, rather than the A4 the rest of the product's documents use.
 * - **No light island.** `app.css` restores every semantic token to its `-on-light` step
 *   inside `[data-paper]`, precisely because a sheet is white paper inside a possibly-dark
 *   document. Without it a shop working in dark mode got a receipt whose «پرداخت‌شده» badge
 *   was `#4CC47F` on white — 2.2:1, effectively invisible — on the one page they keep as
 *   proof of payment.
 *
 * ## One heading, and it belongs to the sheet
 *
 * `AppShell` gets no `title`: on a document screen the sheet's own heading *is* the page
 * heading, and rendering both put «صورتحساب INV-…» in the shell row and «صورتحساب اشتراک»
 * on the paper, 40px apart, saying the same thing twice.
 *
 * Amounts render with Latin digits so the column aligns and so a number copied into an
 * accounting system arrives as a number (design-system rule 4). The gateway reference is
 * the one field support will ask for, so it is given its own row rather than buried.
 */
export default function Receipt({ invoice }: ReceiptProps) {
  return (
    <AppShell>
      <Head title={`صورتحساب ${invoice.number}`} />

      <PrintLayout.A4
        toolbar={
          <div className="flex flex-wrap items-center justify-between gap-4">
            <Link href="/billing" className="text-sm text-brand hover:underline">
              بازگشت به اشتراک
            </Link>

            {/* `printSheet()`, not `window.print()`. Same call today; the difference is
                that the design system owns printing and a page that reaches past it is a
                page that will not get the next fix. */}
            <Button variant="secondary" onClick={printSheet}>
              <PrinterIcon className="size-4" aria-hidden />
              چاپ
            </Button>
          </div>
        }
      >
        {/* `p-[12mm]`, matching the goods-received note. `@page` sets the printer's
            margin; the sheet still needs its own padding on screen, and dropping it put
            «صورتحساب اشتراک» hard against the paper's edge — measured, not guessed. */}
        <article className="p-[12mm] text-black">
          <header className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">صورتحساب اشتراک</h1>
              <p className="mt-1 text-sm text-muted-foreground tabular">{invoice.number}</p>
            </div>

            <StatusBadge status={invoice.status} />
          </header>

          <Separator className="my-6" />

          <table className="w-full text-sm">
            <caption className="sr-only">اقلام صورتحساب</caption>
            <thead>
              <tr className="text-muted-foreground">
                <th scope="col" className="pb-3 text-start font-normal">
                  شرح
                </th>
                {/* `text-start`, which is physical *right* in RTL — where Latin numerals
                    must align so their units digits line up. `text-end` put the column on
                    the wrong edge and left the units ragged. */}
                <th scope="col" className="pb-3 text-start font-normal">
                  مبلغ
                </th>
              </tr>
            </thead>

            <tbody>
              {invoice.lines.map((line, index) => (
                <tr key={`${line.label}-${index}`} className="border-t border-border">
                  <td className="py-3">{line.label}</td>
                  <td className="py-3 text-start tabular">
                    {/* signed: a proration credit is a negative line and must read as one */}
                    <Money rial={line.amount.value} digits="latin" signed />
                  </td>
                </tr>
              ))}
            </tbody>

            <tfoot>
              <tr className="border-t border-border">
                <td className="py-3 text-muted-foreground">جمع</td>
                <td className="py-3 text-start tabular">
                  <Money rial={invoice.subtotal.value} digits="latin" />
                </td>
              </tr>

              {invoice.credit_applied.value > 0 ? (
                <tr>
                  <td className="py-1 text-muted-foreground">اعتبار استفاده‌شده</td>
                  <td className="py-1 text-start tabular">
                    <Money rial={-invoice.credit_applied.value} digits="latin" signed />
                  </td>
                </tr>
              ) : null}

              <tr className="border-t-2 border-foreground/15">
                <td className="pt-4 text-base font-semibold">مبلغ پرداختی</td>
                <td className="pt-4 text-start text-base font-semibold tabular">
                  <Money rial={invoice.total.value} withUnit digits="latin" />
                </td>
              </tr>
            </tfoot>
          </table>

          {invoice.paid_at || invoice.reference ? (
            <>
              <Separator className="my-6" />

              <dl className="grid gap-4 text-sm sm:grid-cols-2">
                {invoice.paid_at ? (
                  <div>
                    <dt className="text-muted-foreground">تاریخ پرداخت</dt>
                    <dd className="mt-1">{formatJalali(invoice.paid_at, { withTime: true })}</dd>
                  </div>
                ) : null}

                {invoice.reference ? (
                  <div>
                    <dt className="text-muted-foreground">کد پیگیری</dt>
                    <dd className="mt-1 tabular">{invoice.reference}</dd>
                  </div>
                ) : null}
              </dl>
            </>
          ) : null}
        </article>
      </PrintLayout.A4>
    </AppShell>
  );
}
