import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface Props {
  ticket: {
    code: string;
    device: string;
    device_colour: string | null;
    device_imei: string | null;
    reported_issue: string;
    party_name: string | null;
    accessories: string[];
    estimate_amount: MoneyValue;
    prepaid_amount: MoneyValue;
    promised_at: string | null;
    created_at: string | null;
    checklist: Array<{ label: string; answer: string }>;
  };
  shop: { name: string; address: string | null; phone: string | null };
  tracking: { url: string | null; qr_svg: string | null };
}

/**
 * قبض پذیرش — the paper the customer walks out with.
 *
 * 80mm thermal, because that is the printer sitting on an Iranian shop counter. This is
 * the only artefact of the whole module the customer physically keeps, so what goes on it
 * is chosen by what they will need it for:
 *
 * - **The tracking QR gets real estate**, not a corner. It is the entire reason the shop
 *   does not get a phone call on Thursday asking whether the phone is ready, and a code
 *   too small to scan on a thermal ribbon is a code nobody scans twice.
 * - **The condition checklist is printed in full.** This is the customer's copy of what
 *   the shop wrote down about their device, and a receipt that records it only on the
 *   shop's side is not evidence, it is an assertion.
 * - **The estimate is printed with the words that limit it.** «برآورد اولیه» rather than
 *   a bare number, because the number will be quoted back.
 * - **The passcode is not on it, ever.** Neither is any hint that one was recorded. A
 *   receipt gets left on counters and photographed.
 */
export default function TicketReceipt({ ticket, shop, tracking }: Props) {
  return (
    <AppShell title={`قبض پذیرش ${ticket.code}`}>
      <Head title={`قبض ${ticket.code}`} />

      <PrintLayout.Thermal80
        toolbar={
          <div className="flex flex-wrap gap-2">
            <Button type="button" onClick={printSheet}>
              <PrinterIcon className="size-4" aria-hidden />
              چاپ قبض
            </Button>
            <Button asChild variant="outline">
              <Link href="/repairs/intake">پذیرش بعدی</Link>
            </Button>
          </div>
        }
      >
        <div className="px-[3mm] py-[4mm] text-[8.5pt] leading-[1.5] text-black">
          <header className="text-center">
            <p className="text-[11pt] font-bold">{shop.name}</p>
            {shop.phone && (
              <p className="text-[8pt]">
                <Num value={shop.phone} variant="ltr" />
              </p>
            )}
            <p className="mt-[1mm] text-[9pt] font-bold">قبض پذیرش تعمیر</p>
          </header>

          <div className="my-[2mm] border-t border-dashed border-black/40" />

          <dl className="space-y-0.5 text-[8pt]">
            <Line label="شماره قبض">
              <span className="text-[10pt] font-bold">
                <Num value={ticket.code} variant="ltr" />
              </span>
            </Line>
            <Line label="تاریخ">
              <span className="tabular">{formatJalali(ticket.created_at, { withTime: true })}</span>
            </Line>
            {ticket.party_name && <Line label="مشتری">{ticket.party_name}</Line>}
          </dl>

          <div className="my-[2mm] border-t border-dashed border-black/40" />

          <dl className="space-y-0.5 text-[8pt]">
            <Line label="دستگاه">{ticket.device}</Line>
            {ticket.device_colour && <Line label="رنگ">{ticket.device_colour}</Line>}
            {ticket.device_imei && (
              <Line label="IMEI">
                <Num value={ticket.device_imei} variant="ltr" />
              </Line>
            )}
          </dl>

          <p className="mt-[2mm] text-[8pt]">
            <span className="font-bold">ایراد اعلامی: </span>
            {ticket.reported_issue}
          </p>

          {ticket.accessories.length > 0 && (
            <p className="mt-[1mm] text-[8pt]">
              <span className="font-bold">همراه دستگاه: </span>
              {ticket.accessories.join('، ')}
            </p>
          )}

          {ticket.checklist.length > 0 && (
            <>
              <div className="my-[2mm] border-t border-dashed border-black/40" />
              <p className="mb-[1mm] text-[8pt] font-bold">وضعیت دستگاه هنگام تحویل</p>
              <dl className="space-y-0.5 text-[7.5pt]">
                {ticket.checklist.map((item) => (
                  <Line key={item.label} label={item.label}>
                    {item.answer}
                  </Line>
                ))}
              </dl>
            </>
          )}

          <div className="my-[2mm] border-t border-dashed border-black/40" />

          <dl className="space-y-0.5 text-[8pt]">
            <Line label="برآورد اولیه">
              <span className="tabular">
                <Money rial={ticket.estimate_amount.value} digits="latin" />
              </span>
            </Line>
            {ticket.prepaid_amount.value > 0 && (
              <Line label="پیش‌پرداخت">
                <span className="tabular">
                  <Money rial={ticket.prepaid_amount.value} digits="latin" />
                </span>
              </Line>
            )}
            {ticket.promised_at && (
              <Line label="وعده تحویل">
                <span className="tabular">{formatJalali(ticket.promised_at)}</span>
              </Line>
            )}
          </dl>

          <p className="mt-[2mm] text-[7pt] leading-relaxed">
            برآورد اولیه است و پس از کارشناسی ممکن است تغییر کند؛ هزینه نهایی پیش از انجام کار به
            اطلاع مشتری می‌رسد.
          </p>

          {tracking.qr_svg && tracking.url && (
            <>
              <div className="my-[2mm] border-t border-dashed border-black/40" />

              {/* The reason this receipt exists in this shape. 30mm — larger than the
                  invoice QR — because a customer scans this one weeks later, off paper
                  that has been in a pocket. */}
              <div className="flex flex-col items-center gap-[1mm]">
                <p className="text-[8pt] font-bold">پیگیری وضعیت تعمیر</p>
                <div
                  className="print-exact size-[30mm]"
                  dangerouslySetInnerHTML={{ __html: tracking.qr_svg }}
                />
                <p className="text-center text-[6.5pt] text-black/70">
                  با موبایل اسکن کنید و وضعیت دستگاه را ببینید
                </p>
              </div>
            </>
          )}

          <p className="mt-[3mm] text-center text-[7pt]">
            تحویل دستگاه فقط با ارائه این قبض انجام می‌شود.
          </p>
        </div>
      </PrintLayout.Thermal80>
    </AppShell>
  );
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-2">
      <dt>{label}</dt>
      <dd className="min-w-0 text-end">{children}</dd>
    </div>
  );
}
