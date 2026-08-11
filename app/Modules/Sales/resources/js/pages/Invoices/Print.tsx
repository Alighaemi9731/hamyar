import { Head } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface Item {
  id: number;
  description: string;
  imei: string | null;
  quantity: number;
  unit_price: MoneyValue;
  discount_amount: MoneyValue;
  vat_amount: MoneyValue;
  line_total: MoneyValue;
  warranty_months: number | null;
}

interface Payment {
  id: number;
  method: string;
  method_label: string;
  amount: MoneyValue;
  reference: string | null;
}

interface Props {
  paper: 'thermal80' | 'a5' | 'a4';
  invoice: {
    id: number;
    number: string | null;
    status: string;
    issued_at: string | null;
    notes: string | null;
    subtotal: MoneyValue;
    discount_amount: MoneyValue;
    vat_amount: MoneyValue;
    shipping_amount: MoneyValue;
    rounding_adjustment: number;
    total: MoneyValue;
    paid_total: MoneyValue;
    outstanding: MoneyValue;
  };
  party: { name: string; company_name: string | null } | null;
  branch: { name: string; address: string | null; phone: string | null };
  salesperson: string | null;
  items: Item[];
  payments: Payment[];
}

/**
 * One invoice, on whichever paper the counter reached for.
 *
 * The thermal receipt is not a narrow version of the A4 — it is a different document
 * for a different purpose. A receipt is handed over in seconds and must be readable at
 * arm's length on a moving roll, so it stacks: one block per line, no columns. The A4
 * is filed, checked and sometimes shown to an auditor, so it tabulates.
 *
 * What both must carry, and the reason each is here:
 *
 * - **The IMEI of every serialized line.** It is the customer's warranty claim and the
 *   shop's proof of which device left the counter.
 * - **The rounding adjustment as its own figure.** An invoice whose lines sum to one
 *   number and whose total is another, with nothing between them, is one the customer
 *   argues with at the counter.
 * - **Every payment separately.** «۵۰٬۰۰۰٬۰۰۰ نقدی + ۳۰٬۰۰۰٬۰۰۰ کارتخوان» is what the
 *   customer remembers doing; a single "paid" figure is not checkable against anything.
 */
export default function InvoicePrint({
  paper,
  invoice,
  party,
  branch,
  salesperson,
  items,
  payments,
}: Props) {
  const title = `فاکتور ${invoice.number ?? ''}`;

  const toolbar = (
    <Button onClick={printSheet}>
      <PrinterIcon className="size-4" />
      چاپ
    </Button>
  );

  const body =
    paper === 'thermal80' ? (
      <ThermalReceipt
        invoice={invoice}
        party={party}
        branch={branch}
        salesperson={salesperson}
        items={items}
        payments={payments}
      />
    ) : (
      <PaperInvoice
        paper={paper}
        invoice={invoice}
        party={party}
        branch={branch}
        salesperson={salesperson}
        items={items}
        payments={payments}
      />
    );

  return (
    <AppShell title={title}>
      <Head title={title} />

      {paper === 'thermal80' ? (
        <PrintLayout.Thermal80 toolbar={toolbar}>{body}</PrintLayout.Thermal80>
      ) : paper === 'a5' ? (
        <PrintLayout.A5 toolbar={toolbar}>{body}</PrintLayout.A5>
      ) : (
        <PrintLayout.A4 toolbar={toolbar}>{body}</PrintLayout.A4>
      )}
    </AppShell>
  );
}

/* --------------------------------------------------------------- thermal -- */

function ThermalReceipt({
  invoice,
  party,
  branch,
  salesperson,
  items,
  payments,
}: Omit<Props, 'paper'>) {
  return (
    <div className="px-[3mm] py-[4mm] text-[8.5pt] leading-[1.5] text-black">
      <header className="text-center">
        <p className="text-[11pt] font-bold">{branch.name}</p>
        {branch.phone && (
          <p className="text-[8pt]">
            <Num value={branch.phone} variant="ltr" />
          </p>
        )}
      </header>

      <div className="my-[2mm] border-t border-dashed border-black/40" />

      <dl className="space-y-0.5 text-[8pt]">
        <Line label="فاکتور">{invoice.number && <Num value={invoice.number} variant="ltr" />}</Line>
        <Line label="تاریخ">
          <span className="tabular">{formatJalali(invoice.issued_at, { withTime: true })}</span>
        </Line>
        {party && <Line label="مشتری">{party.name}</Line>}
        {salesperson && <Line label="فروشنده">{salesperson}</Line>}
      </dl>

      <div className="my-[2mm] border-t border-dashed border-black/40" />

      {/* Stacked, not tabulated: an 80mm roll has no room for columns, and a receipt
          is read at arm's length while the customer waits. */}
      <ul className="space-y-[2mm]">
        {items.map((item) => (
          <li key={item.id}>
            <p className="font-bold">{item.description}</p>
            {item.imei && (
              <p className="text-[7.5pt]">
                IMEI <Num value={item.imei} variant="ltr" />
              </p>
            )}
            <p className="flex justify-between text-[8pt]">
              <span className="tabular">
                <Num value={item.quantity} variant="table" /> ×{' '}
                <Money rial={item.unit_price.value} digits="latin" />
              </span>
              <span className="tabular font-bold">
                <Money rial={item.line_total.value} digits="latin" />
              </span>
            </p>
            {item.warranty_months !== null && (
              <p className="text-[7.5pt]">
                گارانتی <Num value={item.warranty_months} /> ماه
              </p>
            )}
          </li>
        ))}
      </ul>

      <div className="my-[2mm] border-t border-dashed border-black/40" />

      <Totals invoice={invoice} compact />

      {payments.length > 0 && (
        <>
          <div className="my-[2mm] border-t border-dashed border-black/40" />
          <dl className="space-y-0.5 text-[8pt]">
            {payments.map((payment) => (
              <Line key={payment.id} label={payment.method_label}>
                <span className="tabular">
                  <Money rial={payment.amount.value} digits="latin" />
                </span>
              </Line>
            ))}
          </dl>
        </>
      )}

      {invoice.notes && <p className="mt-[3mm] text-center text-[7.5pt]">{invoice.notes}</p>}

      <p className="mt-[4mm] text-center text-[8pt] font-bold">با تشکر از خرید شما</p>
    </div>
  );
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-2">
      <dt>{label}</dt>
      <dd className="min-w-0 truncate text-end">{children}</dd>
    </div>
  );
}

/* ----------------------------------------------------------------- paper -- */

function PaperInvoice({ paper, invoice, party, branch, salesperson, items, payments }: Props) {
  // A5 is the half-page most shops actually hand over; A4 is the one that gets filed.
  const small = paper === 'a5';

  return (
    <div className={cn('text-black', small ? 'p-[8mm] text-[8.5pt]' : 'p-[12mm] text-[10pt]')}>
      <header className="flex flex-wrap items-start justify-between gap-4 border-b border-black/25 pb-3">
        <div>
          <p className={cn('font-bold', small ? 'text-[12pt]' : 'text-[15pt]')}>{branch.name}</p>
          {branch.address && <p className="mt-1 text-[8pt] text-black/70">{branch.address}</p>}
          {branch.phone && (
            <p className="text-[8pt] text-black/70">
              <Num value={branch.phone} variant="ltr" />
            </p>
          )}
        </div>

        <div className="text-end">
          <p className={cn('font-bold', small ? 'text-[12pt]' : 'text-[14pt]')}>فاکتور فروش</p>
          <p className="mt-1 text-[9pt]">
            شماره {invoice.number && <Num value={invoice.number} variant="ltr" />}
          </p>
          <p className="text-[9pt] tabular">
            {formatJalali(invoice.issued_at, { longMonth: true })}
          </p>
        </div>
      </header>

      <dl className="grid grid-cols-2 gap-x-8 gap-y-1 py-3 text-[9pt] sm:grid-cols-3">
        <div>
          <dt className="text-[7.5pt] text-black/60">خریدار</dt>
          <dd>{party?.name ?? 'مشتری نقدی'}</dd>
        </div>
        {party?.company_name && (
          <div>
            <dt className="text-[7.5pt] text-black/60">شرکت</dt>
            <dd>{party.company_name}</dd>
          </div>
        )}
        {salesperson && (
          <div>
            <dt className="text-[7.5pt] text-black/60">فروشنده</dt>
            <dd>{salesperson}</dd>
          </div>
        )}
      </dl>

      {/*
        Fixed layout with explicit column widths. `auto` layout let the long product
        name — which is the normal case for a phone, not an edge one — squeeze the
        money columns until three figures ran together into one unreadable string.
        The description column is the only elastic one; every numeric column is sized
        for its widest realistic value and refuses to wrap.
      */}
      <table className="w-full table-fixed border-collapse text-[8.5pt]">
        <colgroup>
          <col className="w-[7mm]" />
          <col />
          <col className="w-[11mm]" />
          <col className="w-[23mm]" />
          <col className="w-[20mm]" />
          <col className="w-[25mm]" />
        </colgroup>
        <thead>
          <tr className="border-y border-black/25">
            <th className="py-1.5 text-start font-medium">#</th>
            <th className="py-1.5 text-start font-medium">شرح کالا</th>
            <th className="py-1.5 text-end font-medium">تعداد</th>
            <th className="py-1.5 text-end font-medium">مبلغ واحد</th>
            <th className="py-1.5 text-end font-medium">تخفیف</th>
            <th className="py-1.5 text-end font-medium">جمع</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item, index) => (
            <tr key={item.id} className="border-b border-black/10 align-top">
              <td className="py-1.5 tabular">
                <Num value={index + 1} variant="table" />
              </td>
              <td className="py-1.5 pe-2 break-words">
                {/* Long Persian names wrap rather than truncate: the customer has to
                    be able to read what they bought, and a clipped name on a filed
                    invoice is an argument waiting to happen. */}
                <span className="block">{item.description}</span>
                {item.imei && (
                  <span className="block text-[7.5pt] text-black/70">
                    IMEI <Num value={item.imei} variant="ltr" />
                  </span>
                )}
                {item.warranty_months !== null && (
                  <span className="block text-[7.5pt] text-black/70">
                    گارانتی <Num value={item.warranty_months} /> ماه
                  </span>
                )}
              </td>
              <td className="py-1.5 text-end tabular whitespace-nowrap">
                <Num value={item.quantity} variant="table" />
              </td>
              <td className="py-1.5 text-end tabular whitespace-nowrap">
                <Money rial={item.unit_price.value} digits="latin" />
              </td>
              <td className="py-1.5 text-end tabular whitespace-nowrap">
                {item.discount_amount.value > 0 ? (
                  <Money rial={item.discount_amount.value} digits="latin" />
                ) : (
                  '—'
                )}
              </td>
              <td className="py-1.5 text-end tabular whitespace-nowrap font-medium">
                <Money rial={item.line_total.value} digits="latin" />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="mt-4 flex flex-wrap justify-between gap-6">
        {payments.length > 0 && (
          <dl className="min-w-[55mm] flex-1 space-y-1 text-[8.5pt]">
            <dt className="text-[7.5pt] text-black/60">پرداخت</dt>
            {payments.map((payment) => (
              <div key={payment.id} className="flex justify-between gap-4">
                <dd>
                  {payment.method_label}
                  {payment.reference && (
                    <span className="ms-2 text-[7.5pt] text-black/60">
                      <Num value={payment.reference} variant="ltr" />
                    </span>
                  )}
                </dd>
                <dd className="tabular">
                  <Money rial={payment.amount.value} digits="latin" />
                </dd>
              </div>
            ))}
          </dl>
        )}

        <div className="w-[70mm]">
          <Totals invoice={invoice} />
        </div>
      </div>

      {invoice.notes && (
        <p className="mt-6 border-t border-black/15 pt-3 text-[8pt] text-black/70">
          {invoice.notes}
        </p>
      )}

      <div className="mt-[14mm] flex justify-between text-[8.5pt]">
        <span>مهر و امضای فروشنده: ....................</span>
        <span>امضای خریدار: ....................</span>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- totals -- */

function Totals({ invoice, compact = false }: { invoice: Props['invoice']; compact?: boolean }) {
  const size = compact ? 'text-[8pt]' : 'text-[9pt]';

  return (
    <dl className={cn('space-y-1', size)}>
      <Row label="جمع کالاها" value={invoice.subtotal.value} />

      {invoice.discount_amount.value > 0 && (
        <Row label="تخفیف" value={-invoice.discount_amount.value} />
      )}
      {invoice.vat_amount.value > 0 && (
        <Row label="مالیات بر ارزش افزوده" value={invoice.vat_amount.value} />
      )}
      {invoice.shipping_amount.value > 0 && (
        <Row label="هزینه ارسال" value={invoice.shipping_amount.value} />
      )}

      {/* Its own line, always, when it is not zero: the customer must be able to add
          the paper up in front of the salesperson. */}
      {invoice.rounding_adjustment !== 0 && (
        <Row label="گرد کردن" value={invoice.rounding_adjustment} />
      )}

      <div className="flex justify-between gap-4 border-t border-black/25 pt-1 font-bold">
        <dt>مبلغ قابل پرداخت</dt>
        <dd className="tabular">
          <Money rial={invoice.total.value} withUnit digits="latin" />
        </dd>
      </div>

      {invoice.outstanding.value > 0 && (
        <div className="flex justify-between gap-4 font-bold">
          <dt>مانده</dt>
          <dd className="tabular">
            <Money rial={invoice.outstanding.value} digits="latin" />
          </dd>
        </div>
      )}
    </dl>
  );
}

function Row({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex justify-between gap-4">
      <dt>{label}</dt>
      <dd className="tabular">
        {/* bdi: a negative figure beside Persian text throws its sign to the wrong
            side of the number without it. */}
        <bdi>
          {value < 0 ? '−' : ''}
          <Money rial={Math.abs(value)} digits="latin" />
        </bdi>
      </dd>
    </div>
  );
}
