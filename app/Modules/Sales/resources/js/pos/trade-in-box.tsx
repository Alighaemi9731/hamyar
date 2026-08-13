import { SmartphoneIcon, XIcon } from 'lucide-react';

import { ImeiInput } from '@/components/domain/imei-input';
import { type VariantOption, VariantPicker } from '@/components/domain/variant-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import { MoneyField } from '@/components/domain/money-field';

export interface TradeInDraft {
  device_name: string;
  variant: VariantOption | null;
  imei1: string;
  grade: string;
  agreed_price: number;
  hamta_ack: boolean;
}

interface TradeInBoxProps {
  value: TradeInDraft | null;
  onChange: (draft: TradeInDraft | null) => void;
  toman: boolean;
  /** The trade-in is a purchase, and a purchase has to have somebody to buy from. */
  hasParty: boolean;
  errors: Record<string, string>;
}

const GRADES = [
  { value: 'A', label: 'درجه A — بدون خط و خش' },
  { value: 'B', label: 'درجه B — خط و خش جزئی' },
  { value: 'C', label: 'درجه C — خط و خش محسوس' },
  { value: 'D', label: 'درجه D — نیاز به تعمیر' },
];

/**
 * معاوضه, taken at the counter.
 *
 * ## It is a tender, not a discount
 *
 * The panel sits with the payments rather than with the line discounts, because that is
 * what it is: the customer buys a handset at its price and sells the shop their old one,
 * and the second settles part of the first. Putting it in the discount box would compute
 * VAT on a reduced base and leave the shop holding a phone no register knows about.
 *
 * ## Two fields that look redundant and are not
 *
 * The typed **device name** is what the customer and the salesperson agreed a price for
 * — «آیفون ۱۳ سفید ۱۲۸» — and it goes on the invoice. The picked **catalogue line** is
 * what the resulting used handset is filed under. Building the second from the first
 * would fill the catalogue with fourteen spellings of the same phone inside a month.
 *
 * ## The HAMTA tick is not a formality
 *
 * The shop carries real liability when a stolen handset is traded in. This records that
 * the salesperson walked the customer through the ownership transfer — a claim the shop
 * can honestly make at the counter — and the server refuses the intake without it.
 */
export function TradeInBox({ value, onChange, toman, hasParty, errors }: TradeInBoxProps) {
  if (value === null) {
    return (
      <Button
        type="button"
        variant="outline"
        className="w-full"
        onClick={() =>
          onChange({
            device_name: '',
            variant: null,
            imei1: '',
            grade: '',
            agreed_price: 0,
            hamta_ack: false,
          })
        }
      >
        <SmartphoneIcon className="size-4" aria-hidden />
        افزودن دستگاه معاوضه‌ای
      </Button>
    );
  }

  function update(changes: Partial<TradeInDraft>): void {
    onChange({ ...value!, ...changes });
  }

  return (
    <section className="space-y-3 rounded-card border border-border p-4" aria-label="معاوضه">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">دستگاه معاوضه‌ای</h2>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label="حذف معاوضه"
          onClick={() => onChange(null)}
        >
          <XIcon className="size-4" />
        </Button>
      </div>

      {!hasParty && (
        <p className="rounded-control border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
          دستگاه از مشتری خریداری می‌شود، پس باید مشتری را انتخاب کنید — شناسنامه دستگاه باید بگوید
          از چه کسی خریده شده است.
        </p>
      )}

      <div className="space-y-2">
        <Label htmlFor="trade-in-name">دستگاه مشتری</Label>
        <Input
          id="trade-in-name"
          value={value.device_name}
          placeholder="مثلاً: آیفون ۱۳ سفید ۱۲۸"
          onChange={(event) => update({ device_name: event.target.value })}
          aria-invalid={Boolean(errors['trade_in.device_name'])}
        />
        {errors['trade_in.device_name'] && (
          <p className="text-sm text-destructive">{errors['trade_in.device_name']}</p>
        )}
      </div>

      <div className="space-y-2">
        <Label htmlFor="trade-in-variant">این دستگاه در فهرست کالاها</Label>
        <VariantPicker
          id="trade-in-variant"
          value={value.variant}
          onChange={(variant) => update({ variant })}
          serialized
          invalid={Boolean(errors['trade_in.product_variant_id'])}
        />
        <p className="text-2xs text-muted-foreground">
          دستگاه با همین مدل وارد انبار می‌شود و شناسنامه IMEI می‌گیرد.
        </p>
        {errors['trade_in.product_variant_id'] && (
          <p className="text-sm text-destructive">{errors['trade_in.product_variant_id']}</p>
        )}
      </div>

      <ImeiInput
        value={value.imei1}
        onChange={(imei1) => update({ imei1 })}
        label="IMEI دستگاه معاوضه‌ای"
        optional
        error={errors['trade_in.imei1']}
      />

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="trade-in-grade">درجه</Label>
          <Select value={value.grade} onValueChange={(grade) => update({ grade })}>
            <SelectTrigger id="trade-in-grade" className="w-full">
              <SelectValue placeholder="انتخاب کنید" />
            </SelectTrigger>
            <SelectContent dir="rtl">
              {GRADES.map((grade) => (
                <SelectItem key={grade.value} value={grade.value}>
                  {grade.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="trade-in-price">مبلغ توافق‌شده</Label>
          <MoneyField
            id="trade-in-price"
            toman={toman}
            value={value.agreed_price}
            onChange={(agreed_price) => update({ agreed_price })}
          />
          {errors['trade_in.agreed_price'] && (
            <p className="text-sm text-destructive">{errors['trade_in.agreed_price']}</p>
          )}
        </div>
      </div>

      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          className="mt-1 size-4 accent-primary"
          checked={value.hamta_ack}
          onChange={(event) => update({ hamta_ack: event.target.checked })}
        />
        <span>
          مراحل انتقال مالکیت در سامانه همتا را به مشتری توضیح دادم و مدارک هویتی را دیدم.
        </span>
      </label>
      {errors['trade_in.hamta_ack'] && (
        <p className="text-sm text-destructive">{errors['trade_in.hamta_ack']}</p>
      )}

      <p className="text-2xs text-muted-foreground">
        مبلغ توافق‌شده به‌عنوان یک روش پرداخت از مبلغ فاکتور کم می‌شود؛ قیمت کالای فروخته‌شده و
        مالیات آن تغییری نمی‌کند.
      </p>
    </section>
  );
}
