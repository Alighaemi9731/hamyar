import { PlusIcon, XIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
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
import { cn } from '@/lib/utils';

import { MoneyField } from '@/components/domain/money-field';
import type { AccountOption, BasketPayment, PaymentMethodOption } from './types';

interface PaymentBoxProps {
  payments: BasketPayment[];
  onChange: (payments: BasketPayment[]) => void;
  methods: PaymentMethodOption[];
  accounts: AccountOption[];
  /** The invoice total, in rial. What the payments have to add up to. */
  total: number;
  /** Rial already covered by a معاوضه, which settles without money moving. */
  tradedIn?: number;
  /** True when the shop displays toman; the inputs are typed in the display unit. */
  toman: boolean;
  /** A credit sale needs somebody to owe it. */
  hasParty: boolean;
}

/**
 * Split payments, and the change.
 *
 * ## Why one sale takes several rows
 *
 * An Iranian shop routinely settles one phone across three tenders: part cash, part
 * card-to-card into the owner's personal account, the rest on a post-dated cheque.
 * Collapsing that into a single "paid" figure loses the cash-box reconciliation, the
 * trace number the bank will ask for, and the cheque somebody has to chase in two
 * months. So each tender is its own row carrying its own evidence.
 *
 * ## Change is arithmetic the counter does out loud
 *
 * The cashier types what the customer handed over. The change is
 * `tendered − what is due`, shown large, because that is the number being counted back
 * into somebody's hand and getting it wrong is the fastest way to a dispute.
 *
 * What gets *stored* is the settled amount, not the tender: the drawer keeps the
 * difference only for as long as it takes to hand it back. The tendered figure rides
 * along so the receipt can be reprinted next week and still say what it said.
 *
 * ## Amounts are typed in the shop's display unit
 *
 * Almost always toman. The conversion to rial happens once, at submit, in a single place
 * — a field that stores rial while showing toman is how a shop sells a phone for a tenth
 * of its price.
 */
export function PaymentBox({
  payments,
  onChange,
  methods,
  accounts,
  total,
  tradedIn = 0,
  toman,
  hasParty,
}: PaymentBoxProps) {
  const settled = payments.reduce((sum, payment) => sum + payment.amount, 0) + tradedIn;
  const due = Math.max(0, total - settled);

  const tendered = payments.reduce(
    (sum, payment) => sum + (payment.tendered_amount ?? payment.amount),
    0
  );
  const change = Math.max(0, tendered - total);

  function update(id: string, changes: Partial<BasketPayment>): void {
    onChange(payments.map((payment) => (payment.id === id ? { ...payment, ...changes } : payment)));
  }

  function add(): void {
    const fallback = accounts.find((account) => account.is_default) ?? accounts[0];

    onChange([
      ...payments,
      {
        // `crypto.randomUUID` rather than an index: rows get removed from the middle, and
        // an index key makes React reuse the wrong input's DOM node — which shows up as
        // a typed reference number jumping to another row.
        id: crypto.randomUUID(),
        method: 'cash',
        // Pre-filled with what is left owing. The overwhelmingly common sale is one
        // tender for the whole amount, and making somebody retype the total they are
        // looking at is the sort of friction that adds up a hundred times a day.
        amount: due,
        tendered_amount: null,
        account_id: fallback?.id ?? null,
        reference: '',
      },
    ]);
  }

  return (
    <section className="space-y-3" aria-label="پرداخت">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">پرداخت</h2>
        <Button type="button" variant="outline" size="sm" onClick={add}>
          <PlusIcon className="size-4" aria-hidden />
          افزودن روش پرداخت
        </Button>
      </div>

      {payments.length === 0 && (
        <p className="rounded-control border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
          {hasParty
            ? 'بدون پرداخت، کل مبلغ به حساب مشتری بدهکار می‌شود.'
            : 'برای فروش نسیه باید مشتری را انتخاب کنید.'}
        </p>
      )}

      <ul className="space-y-3">
        {payments.map((payment) => {
          const method = methods.find((option) => option.value === payment.method);

          return (
            <li key={payment.id} className="space-y-2 rounded-control border border-border p-3">
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1 space-y-2">
                  <Label htmlFor={`method-${payment.id}`} className="text-2xs">
                    روش
                  </Label>
                  <Select
                    value={payment.method}
                    onValueChange={(value) => {
                      const picked = methods.find((option) => option.value === value);

                      update(payment.id, {
                        method: value,
                        // A method that settles nowhere carries no account. The row was
                        // pre-filled with the default cash box, and the field is hidden
                        // for چک — so without this the id stayed in state and a
                        // post-dated cheque was reported against the till.
                        account_id: picked?.needs_account ? payment.account_id : null,
                        // Likewise the reference: a trace number typed for a terminal
                        // payment is not the serial of the cheque it just became.
                        reference: picked?.needs_reference ? payment.reference : '',
                      });
                    }}
                  >
                    <SelectTrigger id={`method-${payment.id}`} className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    {/* dir on the Content, per the RTL rule for Radix portals. */}
                    <SelectContent dir="rtl">
                      {methods.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="min-w-0 flex-1 space-y-2">
                  <Label htmlFor={`amount-${payment.id}`} className="text-2xs">
                    مبلغ
                  </Label>
                  <MoneyField
                    id={`amount-${payment.id}`}
                    toman={toman}
                    value={payment.amount}
                    onChange={(rial) => update(payment.id, { amount: rial })}
                  />
                </div>

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="حذف این پرداخت"
                  className="mt-6"
                  onClick={() => onChange(payments.filter((row) => row.id !== payment.id))}
                >
                  <XIcon className="size-4" />
                </Button>
              </div>

              {method?.needs_account && (
                <div className="space-y-2">
                  <Label htmlFor={`account-${payment.id}`} className="text-2xs">
                    به حساب
                  </Label>
                  <Select
                    value={payment.account_id === null ? '' : String(payment.account_id)}
                    onValueChange={(value) => update(payment.id, { account_id: Number(value) })}
                  >
                    <SelectTrigger id={`account-${payment.id}`} className="w-full">
                      <SelectValue placeholder="صندوق یا حساب را انتخاب کنید" />
                    </SelectTrigger>
                    <SelectContent dir="rtl">
                      {accounts.map((account) => (
                        <SelectItem key={account.id} value={String(account.id)}>
                          {account.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}

              {method?.needs_reference && (
                <div className="space-y-2">
                  <Label htmlFor={`reference-${payment.id}`} className="text-2xs">
                    شماره پیگیری / سریال
                  </Label>
                  <Input
                    id={`reference-${payment.id}`}
                    dir="ltr"
                    className="tabular"
                    value={payment.reference}
                    onChange={(event) => update(payment.id, { reference: event.target.value })}
                  />
                </div>
              )}

              {/* Only cash is handed over in round notes. Offering a "tendered" box for a
                  card payment would invite a cashier to record change that no drawer
                  ever gave. */}
              {payment.method === 'cash' && (
                <div className="space-y-2">
                  <Label htmlFor={`tendered-${payment.id}`} className="text-2xs">
                    دریافتی از مشتری (اختیاری)
                  </Label>
                  <MoneyField
                    id={`tendered-${payment.id}`}
                    toman={toman}
                    value={payment.tendered_amount ?? 0}
                    onChange={(rial) =>
                      update(payment.id, { tendered_amount: rial === 0 ? null : rial })
                    }
                  />
                </div>
              )}
            </li>
          );
        })}
      </ul>

      <dl className="space-y-1 rounded-control bg-muted/40 px-3 py-3 text-sm">
        {tradedIn > 0 && (
          <div className="flex items-baseline justify-between">
            <dt className="text-muted-foreground">بابت معاوضه</dt>
            <dd data-testid="pos-traded-in">
              <Money rial={tradedIn} digits="latin" withUnit />
            </dd>
          </div>
        )}

        <div className="flex items-baseline justify-between">
          <dt className="text-muted-foreground">پرداخت‌شده</dt>
          <dd data-testid="pos-settled">
            <Money rial={settled} digits="latin" withUnit />
          </dd>
        </div>

        <div className="flex items-baseline justify-between">
          <dt className={cn('text-muted-foreground', due > 0 && 'text-warning')}>
            {due > 0 ? 'باقی‌مانده' : 'تسویه'}
          </dt>
          <dd data-testid="pos-due" className={cn(due > 0 && 'text-warning')}>
            <Money rial={due} digits="latin" withUnit />
          </dd>
        </div>

        {change > 0 && (
          // The number being counted back into somebody's hand. Given the most visual
          // weight on the panel on purpose.
          <div className="flex items-baseline justify-between border-t border-border pt-2 text-lg font-semibold">
            <dt>باقی‌مانده مشتری</dt>
            <dd data-testid="pos-change" className="text-success">
              <Money rial={change} digits="latin" withUnit />
            </dd>
          </div>
        )}
      </dl>

      {due > 0 && !hasParty && (
        <p className="rounded-control border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
          مبلغ فاکتور کامل پرداخت نشده است. برای فروش نسیه باید مشتری را انتخاب کنید.
        </p>
      )}
    </section>
  );
}
