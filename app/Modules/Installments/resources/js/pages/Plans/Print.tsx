import { Head } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { formatJalali } from '@/lib/jalali';

import { type InstallmentPlanPayload, ScheduleTable } from '../../installments/schedule-table';

interface Props {
  plan: InstallmentPlanPayload;
  shop: { name: string | null; branch: string };
}

/**
 * قرارداد فروش اقساطی — the paper both sides sign.
 *
 * ## A4, and it uses the same schedule component as the screen
 *
 * The whole purpose of a contract is that the paper and the system say the same thing.
 * A print-only copy of the schedule is a copy that drifts, and the customer is holding
 * the version nobody checked.
 *
 * ## What is on it, and why
 *
 * Two signature blocks, because an instalment contract is worthless unsigned, and the
 * ضامن signs separately from the buyer — a guarantor who never put their name on
 * anything is not a guarantor.
 *
 * The terms paragraph states the flat-profit basis in words. «۲۰٪ سود» means different
 * things to different people, and the sentence that says it is a flat markup on the
 * financed amount is the one that settles an argument two months in.
 *
 * No shop hostname anywhere on it (golden rule 1b) — the apex domain is not chosen, and
 * a printed contract is the worst possible place to hardcode one.
 */
export default function InstallmentContractPrint({ plan, shop }: Props) {
  return (
    <PrintLayout.A4
      toolbar={
        <Button type="button" onClick={printSheet}>
          <PrinterIcon className="size-4" aria-hidden />
          چاپ
        </Button>
      }
    >
      <Head title={`قرارداد ${plan.number}`} />

      <div className="space-y-6 p-8">
        <header className="space-y-1 border-b border-black/20 pb-4 text-center">
          <h1 className="text-xl font-bold">قرارداد فروش اقساطی</h1>
          <p className="text-sm">
            {shop.name} — {shop.branch}
          </p>
          <p className="tabular text-xs">
            شماره قرارداد {plan.number}
            {plan.invoice && <> — فاکتور {plan.invoice.number}</>}
          </p>
        </header>

        <section className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <h2 className="mb-1 font-semibold">خریدار</h2>
            <p>{plan.party.name}</p>
          </div>
          <div>
            <h2 className="mb-1 font-semibold">ضامن</h2>
            <p>{plan.guarantor?.name ?? '—'}</p>
          </div>
        </section>

        <section className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
          <Figure label="پیش‌پرداخت دریافت‌شده" rial={plan.down_payment.value} />
          <Figure label="مبلغ تقسیط‌شده" rial={plan.principal.value} />
          <Figure
            label={`سود فروش اقساطی (${plan.profit_percent}٪ تخت)`}
            rial={plan.profit_amount.value}
          />
          <Figure label="مجموع اقساط" rial={plan.total_payable.value} bold />
          <Figure label="مبلغ کل قرارداد" rial={plan.contract_total.value} bold />
          <div className="flex items-baseline justify-between">
            <span>تعداد اقساط</span>
            <span className="tabular">
              <Num value={plan.installment_count} variant="table" /> قسط، هر{' '}
              <Num value={plan.interval_months} variant="table" /> ماه
            </span>
          </div>
        </section>

        <ScheduleTable plan={plan} withStatus={false} />

        <section className="space-y-2 text-xs leading-6">
          <h2 className="text-sm font-semibold">شرایط</h2>
          <p>
            سود این قرارداد به‌صورت <strong>تخت</strong> روی مبلغ تقسیط‌شده محاسبه شده است و با
            پرداخت اقساط کاهش نمی‌یابد. مجموع اقساط، مبلغ تقسیط‌شده به‌علاوه سود است و باقی‌مانده
            تقسیم روی قسط آخر منظور شده تا جمع اقساط دقیقاً برابر مبلغ قرارداد باشد.
          </p>
          <p>
            سررسید اولین قسط {formatJalali(plan.first_due_at)} است و سررسید اقساط بعدی بر پایه تقویم
            هجری شمسی محاسبه می‌شود.
          </p>
          {plan.notes && <p>{plan.notes}</p>}
        </section>

        {/* An unsigned instalment contract is worthless, and the guarantor signs
            separately from the buyer — a ضامن who never put their name on anything is
            not a guarantor. */}
        <section className="grid grid-cols-2 gap-8 pt-8 text-sm">
          <SignatureBlock title="امضای خریدار" name={plan.party.name} />
          <SignatureBlock title="امضای ضامن" name={plan.guarantor?.name ?? ''} />
        </section>
      </div>
    </PrintLayout.A4>
  );
}

function Figure({ label, rial, bold = false }: { label: string; rial: number; bold?: boolean }) {
  return (
    <div className={`flex items-baseline justify-between ${bold ? 'font-semibold' : ''}`}>
      <span>{label}</span>
      <Money rial={rial} digits="latin" withUnit />
    </div>
  );
}

function SignatureBlock({ title, name }: { title: string; name: string }) {
  return (
    <div className="space-y-8">
      <p className="font-semibold">{title}</p>
      <p className="border-t border-black/40 pt-1 text-xs">{name || ' '}</p>
    </div>
  );
}
