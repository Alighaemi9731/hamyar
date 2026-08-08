import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
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
 * Amounts render with Latin digits so the column aligns and so a number copied into an
 * accounting system arrives as a number (design-system rule 4). The gateway reference is
 * the one field support will ask for, so it is given its own row rather than buried.
 */
export default function Receipt({ invoice }: ReceiptProps) {
  return (
    <AppShell title={`صورتحساب ${invoice.number}`}>
      <Head title={`صورتحساب ${invoice.number}`} />

      <div className="mx-auto max-w-2xl">
        <div className="mb-6 flex items-center justify-between gap-4 print:hidden">
          <Link href="/billing" className="text-sm text-brand hover:underline">
            بازگشت به اشتراک
          </Link>

          <Button variant="secondary" size="sm" onClick={() => window.print()}>
            <PrinterIcon className="size-4" aria-hidden />
            چاپ
          </Button>
        </div>

        <article className="rounded-card border border-border bg-card p-8">
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
                <th scope="col" className="pb-3 text-end font-normal">
                  مبلغ
                </th>
              </tr>
            </thead>

            <tbody>
              {invoice.lines.map((line, index) => (
                <tr key={`${line.label}-${index}`} className="border-t border-border">
                  <td className="py-3">{line.label}</td>
                  <td className="py-3 text-end">
                    {/* signed: a proration credit is a negative line and must read as one */}
                    <Money rial={line.amount.value} digits="latin" signed />
                  </td>
                </tr>
              ))}
            </tbody>

            <tfoot>
              <tr className="border-t border-border">
                <td className="py-3 text-muted-foreground">جمع</td>
                <td className="py-3 text-end">
                  <Money rial={invoice.subtotal.value} digits="latin" />
                </td>
              </tr>

              {invoice.credit_applied.value > 0 ? (
                <tr>
                  <td className="py-1 text-muted-foreground">اعتبار استفاده‌شده</td>
                  <td className="py-1 text-end">
                    <Money rial={-invoice.credit_applied.value} digits="latin" signed />
                  </td>
                </tr>
              ) : null}

              <tr className="border-t-2 border-foreground/15">
                <td className="pt-4 text-base font-semibold">مبلغ پرداختی</td>
                <td className="pt-4 text-end text-base font-semibold">
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
      </div>
    </AppShell>
  );
}
