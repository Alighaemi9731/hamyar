import { Link } from '@inertiajs/react';
import { CalendarClockIcon, FileTextIcon, PrinterIcon, RotateCcwIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import type { Invoice, InvoiceAbilities } from './types';

interface InvoiceActionsProps {
  invoice: Invoice;
  can: InvoiceAbilities;
}

const PAPERS = [
  { key: 'thermal80', label: 'رسید حرارتی', hint: 'رول ۸۰ میلی‌متری' },
  { key: 'a5', label: 'چاپ A5', hint: 'نصف برگ' },
  { key: 'a4', label: 'چاپ A4', hint: 'برگ کامل' },
] as const;

/**
 * What a shop can do with a finished sale.
 *
 * ## Six flat buttons became one primary and a short list
 *
 * The header used to carry six controls, every one of them `variant="outline"` — three
 * paper sizes, an instalment wizard, a return, and an irreversible void, all at the same
 * weight. Nothing said which one the reader wanted, and «ابطال» was styled exactly like
 * «چاپ A5». At 375 they summed 607px in a 343px lane and wrapped onto three rows.
 *
 * The three papers are one intent — *print this* — so they collapse into one brand-filled
 * button and a menu. That leaves the header with a primary, at most two outlined
 * operational actions, and nothing destructive at all.
 *
 * ## Void is deliberately absent from here
 *
 * It lives at the foot of the page in its own separated region. An action that reverses
 * stock, reverses the ledger and cannot be undone should not sit one careless tap from
 * «چاپ A4», and putting it at the end means reaching it is a decision rather than a
 * reflex. See `VoidPanel`.
 *
 * ## Nothing appears that would refuse the operator
 *
 * A settled invoice has nothing to schedule, so the instalment wizard is not offered on
 * one — the old page already got this right and it is preserved: offering the form and
 * refusing at the end is worse than not offering it.
 */
export function InvoiceActions({ invoice, can }: InvoiceActionsProps) {
  const isFinal = invoice.status === 'final';

  const canSchedule =
    isFinal && can.create && invoice.totals.outstanding.value > 0 && !invoice.installment_plan;

  return (
    <>
      {isFinal && (
        /* `dir="rtl"` on the Root for menu primitives — design-system rule 2. It drives
           keyboard navigation as well as placement. */
        <DropdownMenu dir="rtl">
          <DropdownMenuTrigger asChild>
            <Button>
              <PrinterIcon className="size-4" aria-hidden />
              چاپ فاکتور
            </Button>
          </DropdownMenuTrigger>

          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>روی چه کاغذی؟</DropdownMenuLabel>
            <DropdownMenuSeparator />

            {PAPERS.map((paper) => (
              <DropdownMenuItem key={paper.key} asChild>
                {/* A real link, not a router visit: print opens its own tab and the
                    operator keeps the invoice they were reading. */}
                <a
                  href={`/sales/invoices/${invoice.id}/print/${paper.key}`}
                  target="_blank"
                  rel="noreferrer"
                  className="flex-col items-start gap-0.5"
                >
                  <span>{paper.label}</span>
                  <span className="text-2xs text-muted-foreground">{paper.hint}</span>
                </a>
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      )}

      {canSchedule && (
        <Button asChild variant="outline">
          <Link href={`/installments/invoices/${invoice.id}/plan/create`}>
            <CalendarClockIcon className="size-4" aria-hidden />
            فروش اقساطی
          </Link>
        </Button>
      )}

      {invoice.installment_plan && (
        <Button asChild variant="outline">
          <Link href={`/installments/plans/${invoice.installment_plan.id}`}>
            <FileTextIcon className="size-4" aria-hidden />
            قرارداد {invoice.installment_plan.number}
          </Link>
        </Button>
      )}

      {isFinal && can.return && (
        <Button asChild variant="outline">
          <Link href={`/sales/invoices/${invoice.id}/returns/create`}>
            <RotateCcwIcon className="size-4" aria-hidden />
            برگشت از فروش
          </Link>
        </Button>
      )}
    </>
  );
}
