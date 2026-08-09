import { Head } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface Props {
  invoice: {
    number: string;
    status: string;
    supplier: string | null;
    warehouse: string;
    branch: string;
    branch_address: string | null;
    branch_phone: string | null;
    issued_at: string | null;
    received_at: string | null;
    subtotal: MoneyValue;
    landed_total: MoneyValue;
    total: MoneyValue;
  };
  standard_lines: {
    id: number;
    name: string;
    quantity: number;
    unit_cost: MoneyValue;
    line_total: MoneyValue;
  }[];
  unit_lines: { id: number; name: string; imei1: string | null; unit_cost: MoneyValue }[];
}

/**
 * The goods-received note.
 *
 * Prints the costs **including** their share of freight and customs, because that is
 * what the goods actually cost the shop — a GRN quoting only the supplier's line price
 * understates the stock it is evidencing.
 *
 * Every IMEI is listed individually. The whole point of a GRN for a phone shipment is
 * that someone can stand at the shelf and tick off fifteen-digit numbers.
 */
export default function Grn({
  invoice,
  standard_lines: standardLines,
  unit_lines: unitLines,
}: Props) {
  return (
    <AppShell title={`رسید انبار ${invoice.number}`}>
      <Head title={`رسید انبار ${invoice.number}`} />

      <PrintLayout.A4
        toolbar={
          <Button onClick={printSheet}>
            <PrinterIcon className="size-4" />
            چاپ
          </Button>
        }
      >
        <div className="p-[12mm] text-black">
          <header className="flex flex-wrap items-start justify-between gap-4 border-b border-black/20 pb-4">
            <div>
              <p className="text-base font-bold">{invoice.branch}</p>
              {invoice.branch_address && (
                <p className="mt-1 text-[9pt] text-black/70">{invoice.branch_address}</p>
              )}
              {invoice.branch_phone && (
                <p className="text-[9pt] text-black/70">
                  <Num value={invoice.branch_phone} variant="ltr" />
                </p>
              )}
            </div>

            <div className="text-end">
              <p className="text-base font-bold">رسید انبار</p>
              <p className="mt-1 text-[9pt]">
                شماره <Num value={invoice.number} variant="ltr" />
              </p>
              <p className="text-[9pt] tabular">
                {formatJalali(invoice.received_at ?? invoice.issued_at, { longMonth: true })}
              </p>
            </div>
          </header>

          <dl className="grid grid-cols-2 gap-x-8 gap-y-2 py-4 text-[10pt] sm:grid-cols-3">
            <div>
              <dt className="text-[8pt] text-black/60">تأمین‌کننده</dt>
              <dd>{invoice.supplier ?? 'موجودی اولیه'}</dd>
            </div>
            <div>
              <dt className="text-[8pt] text-black/60">انبار</dt>
              <dd>{invoice.warehouse}</dd>
            </div>
            <div>
              <dt className="text-[8pt] text-black/60">تعداد اقلام</dt>
              <dd className="tabular">
                <Num value={standardLines.length + unitLines.length} />
              </dd>
            </div>
          </dl>

          {unitLines.length > 0 && (
            <section className="mt-2">
              <h2 className="mb-2 text-[10pt] font-bold">دستگاه‌های سریال‌دار</h2>
              <table className="w-full border-collapse text-[9pt]">
                <thead>
                  <tr className="border-y border-black/20 text-start">
                    <th className="py-1.5 text-start font-medium">ردیف</th>
                    <th className="py-1.5 text-start font-medium">کالا</th>
                    <th className="py-1.5 text-start font-medium">IMEI</th>
                    <th className="py-1.5 text-end font-medium">بهای تمام‌شده</th>
                    <th className="w-[16mm] py-1.5 text-center font-medium">تأیید</th>
                  </tr>
                </thead>
                <tbody>
                  {unitLines.map((line, index) => (
                    <tr key={line.id} className="border-b border-black/10">
                      <td className="py-1.5 tabular">
                        <Num value={index + 1} variant="table" />
                      </td>
                      <td className="py-1.5">{line.name}</td>
                      <td className="py-1.5">
                        {line.imei1 && <Num value={line.imei1} variant="ltr" />}
                      </td>
                      <td className="py-1.5 text-end tabular">
                        <Money rial={line.unit_cost.value} digits="latin" />
                      </td>
                      {/* An empty box, on purpose: this is the column somebody ticks
                          with a pen while standing at the shelf. */}
                      <td className="py-1.5 text-center">
                        <span className="mx-auto block size-[4mm] border border-black/40" />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}

          {standardLines.length > 0 && (
            <section className="mt-6">
              <h2 className="mb-2 text-[10pt] font-bold">کالاهای عادی</h2>
              <table className="w-full border-collapse text-[9pt]">
                <thead>
                  <tr className="border-y border-black/20">
                    <th className="py-1.5 text-start font-medium">کالا</th>
                    <th className="py-1.5 text-end font-medium">تعداد</th>
                    <th className="py-1.5 text-end font-medium">بهای واحد</th>
                    <th className="py-1.5 text-end font-medium">جمع</th>
                    <th className="w-[16mm] py-1.5 text-center font-medium">تأیید</th>
                  </tr>
                </thead>
                <tbody>
                  {standardLines.map((line) => (
                    <tr key={line.id} className="border-b border-black/10">
                      <td className="py-1.5">{line.name}</td>
                      <td className="py-1.5 text-end tabular">
                        <Num value={line.quantity} variant="table" />
                      </td>
                      <td className="py-1.5 text-end tabular">
                        <Money rial={line.unit_cost.value} digits="latin" />
                      </td>
                      <td className="py-1.5 text-end tabular">
                        <Money rial={line.line_total.value} digits="latin" />
                      </td>
                      <td className="py-1.5 text-center">
                        <span className="mx-auto block size-[4mm] border border-black/40" />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}

          <div className="mt-6 flex justify-end">
            <dl className="w-[70mm] space-y-1 text-[10pt]">
              <div className="flex justify-between">
                <dt>جمع اقلام</dt>
                <dd className="tabular">
                  <Money rial={invoice.subtotal.value} digits="latin" />
                </dd>
              </div>
              {invoice.landed_total.value > 0 && (
                <div className="flex justify-between">
                  <dt>هزینه‌های سربار</dt>
                  <dd className="tabular">
                    <Money rial={invoice.landed_total.value} digits="latin" />
                  </dd>
                </div>
              )}
              <div className="flex justify-between border-t border-black/20 pt-1 font-bold">
                <dt>مبلغ کل</dt>
                <dd className="tabular">
                  <Money rial={invoice.total.value} withUnit digits="latin" />
                </dd>
              </div>
            </dl>
          </div>

          <div className="mt-[18mm] flex justify-between text-[9pt]">
            <span>تحویل‌دهنده: ....................</span>
            <span>تحویل‌گیرنده: ....................</span>
            <span>انباردار: ....................</span>
          </div>
        </div>
      </PrintLayout.A4>
    </AppShell>
  );
}
