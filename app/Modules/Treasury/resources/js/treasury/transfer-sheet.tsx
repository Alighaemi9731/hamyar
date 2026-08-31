import { useForm } from '@inertiajs/react';
import { ArrowLeftRightIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { MoneyField } from '@/components/domain/money-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

import { type AccountRow, groupByKind } from './types';

interface TransferSheetProps {
  accounts: AccountRow[];
  /** The shop displays toman; `MoneyField` still stores integer rial. */
  toman: boolean;
}

/** Exactly the five keys `TreasuryController::transfer` validates. */
interface TransferData {
  from_account_id: number | null;
  to_account_id: number | null;
  amount: number;
  fee: number;
  reference: string;
}

/** Every key the endpoint validates, so `FormErrors` shows only what has no field. */
const FIELD_KEYS = ['from_account_id', 'to_account_id', 'amount', 'fee', 'reference'];

/**
 * Moving money between two places, in a sheet.
 *
 * ## Why a sheet and not an inline panel
 *
 * The form used to expand into the page above the accounts, which pushed every balance
 * down 145px on a desktop and clean off the screen on a phone — while asking how much
 * money to move. Deciding an amount requires seeing what is in the source account, so
 * the panel was hiding the one thing it needed. A sheet leaves the grid on screen
 * behind it, and echoes the source balance inside the form as well.
 *
 * ## The failure this rewrite exists to end
 *
 * The endpoint validates five keys and the old panel rendered exactly one of them
 * (`transfer`). Every field-level rejection — a missing account, a zero amount, the
 * `different` rule — landed nowhere at all: the server answered 302, the page
 * re-rendered identically, and the button appeared not to work.
 *
 * Two things fix it together, and both are needed. Each field renders its own error
 * beneath it, and `<FormErrors>` catches everything that has no field to sit under,
 * including the `transfer` key a `RuntimeException` in the service arrives as.
 *
 * ## The destination cannot be the source
 *
 * `different:from_account_id` is enforced on the server and always will be. Here the
 * chosen source is simply removed from the destination list, so the commonest way to
 * hit that rule is no longer reachable — and on a one-account shop the whole action is
 * disabled upstream rather than offering a form that cannot succeed.
 *
 * ## The fee is shown, not assumed
 *
 * The source is debited amount **plus** fee while the destination receives the amount.
 * That is a deliberate rule so the two balances stay right, and it used to live only in
 * a code comment. The summary line states both figures before anything is submitted.
 */
export function TransferSheet({ accounts, toman }: TransferSheetProps) {
  const [open, setOpen] = useState(false);

  const form = useForm<TransferData>({
    from_account_id: null,
    to_account_id: null,
    amount: 0,
    fee: 0,
    reference: '',
  });

  /**
   * Set a field and drop the error it was carrying.
   *
   * `setData` alone leaves the message and the red border in place, so a form that had
   * been corrected in every field still read as rejected — on a screen that moves money,
   * where "did my fix register?" is the one question the operator must never have to
   * ask. Every write goes through here so no call site can forget.
   */
  function set<K extends keyof TransferData>(key: K, value: TransferData[K]): void {
    // Inertia's `setData` generic does not admit the nullable members of this form's own
    // shape. The value is that field's declared type, so the cast is safe and stays local
    // to this one line rather than spreading `as never` across five call sites.
    form.setData(key, value as never);
    form.clearErrors(key);
  }

  const from = accounts.find((account) => account.id === form.data.from_account_id) ?? null;
  const to = accounts.find((account) => account.id === form.data.to_account_id) ?? null;

  const debit = form.data.amount + form.data.fee;
  const remaining = from ? from.balance.value - debit : null;

  function close(): void {
    setOpen(false);
    form.reset();
    form.clearErrors();
  }

  return (
    <Sheet
      open={open}
      onOpenChange={(next) => {
        if (next) {
          setOpen(true);
        } else {
          close();
        }
      }}
    >
      <SheetTrigger asChild>
        <Button>
          <ArrowLeftRightIcon className="size-4" aria-hidden />
          انتقال بین حساب‌ها
        </Button>
      </SheetTrigger>

      {/* side="right" is the reading-start edge in RTL — the sheet enters from the same
          side as the sidebar, which is where a Persian reader's eye already is. */}
      <SheetContent
        side="right"
        dir="rtl"
        className="flex w-full! flex-col gap-0 p-0 sm:max-w-md!"
        onOpenAutoFocus={(event) => event.preventDefault()}
      >
        {/* `pe-14` is repeated because the blanket `p-5` would otherwise merge away the
            close-button lane the base header reserves. */}
        <SheetHeader className="border-b border-border p-5 pe-14">
          <SheetTitle>انتقال بین حساب‌ها</SheetTitle>
          <SheetDescription>
            مبلغ از حساب مبدأ کم و به حساب مقصد اضافه می‌شود. کارمزد جدا از مبلغ، از مبدأ برداشته
            می‌شود.
          </SheetDescription>
        </SheetHeader>

        <form
          id="transfer-form"
          className="flex-1 space-y-5 overflow-y-auto p-5"
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/treasury/transfers', {
              preserveScroll: true,
              // Explicit rather than relying on Inertia's automatic error handling: if
              // the sheet unmounted on a rejected submit, the errors below would render
              // into a panel nobody can see — which is the original bug wearing a hat.
              preserveState: true,
              onSuccess: close,
            });
          }}
        >
          {/* Anything the server refused that has no field of its own — `transfer`, the
              key a RuntimeException from TransferBetweenAccounts arrives under. */}
          <FormErrors errors={form.errors} handled={FIELD_KEYS} />

          <Field
            id="transfer-from"
            label="از حساب"
            error={form.errors.from_account_id}
            hint={
              from ? (
                <>
                  موجودی فعلی: <Money rial={from.balance.value} withUnit />
                </>
              ) : undefined
            }
          >
            <AccountSelect
              id="transfer-from"
              value={form.data.from_account_id}
              options={accounts}
              invalid={Boolean(form.errors.from_account_id)}
              onChange={(id) => {
                set('from_account_id', id);

                // Choosing a source that is already the destination would leave the
                // form in the one state the server always refuses.
                if (form.data.to_account_id === id) {
                  set('to_account_id', null);
                }
              }}
            />
          </Field>

          <Field id="transfer-to" label="به حساب" error={form.errors.to_account_id}>
            <AccountSelect
              id="transfer-to"
              value={form.data.to_account_id}
              options={accounts.filter((account) => account.id !== form.data.from_account_id)}
              invalid={Boolean(form.errors.to_account_id)}
              onChange={(id) => set('to_account_id', id)}
            />
          </Field>

          <div className="grid gap-5 sm:grid-cols-2">
            <Field id="transfer-amount" label="مبلغ" error={form.errors.amount}>
              <MoneyField
                id="transfer-amount"
                toman={toman}
                value={form.data.amount}
                onChange={(value) => set('amount', value)}
                aria-invalid={Boolean(form.errors.amount)}
                aria-describedby={form.errors.amount ? 'transfer-amount-error' : undefined}
              />
            </Field>

            <Field id="transfer-fee" label="کارمزد" error={form.errors.fee}>
              <MoneyField
                id="transfer-fee"
                toman={toman}
                value={form.data.fee}
                onChange={(value) => set('fee', value)}
                aria-invalid={Boolean(form.errors.fee)}
                aria-describedby={form.errors.fee ? 'transfer-fee-error' : undefined}
              />
            </Field>
          </div>

          <Field id="transfer-reference" label="شرح" error={form.errors.reference}>
            <Input
              id="transfer-reference"

              value={form.data.reference}
              aria-invalid={Boolean(form.errors.reference)}
              onChange={(event) => set('reference', event.target.value)}
            />
          </Field>

          {/*
            The arithmetic, before it happens.

            The fee gets its own line. Showing only «برداشت ۵٬۰۱۲٬۰۰۰» and «واریز
            ۵٬۰۰۰٬۰۰۰» leaves the operator to work out why the two differ — which is
            precisely the subtraction this panel exists to do for them.

            Direction is carried by the words «برداشت» and «واریز», not by colour. Red
            and green mean "unreconciled" and "checked" two hundred pixels away on the
            same screen, and a deliberate debit is not an error. Only the one case that
            genuinely warrants a warning — a source pushed below zero — takes a tone.

            `bg-background` rather than `bg-surface`: in the dark theme `--surface`,
            `--muted` and `--card` are all #1d1d1f, so a panel inside this (now
            `bg-card`) sheet would have been defined by its hairline alone.
          */}
          {from && to && form.data.amount > 0 && (
            <dl className="space-y-2 rounded-control border border-border bg-background p-4 text-sm">
              <div className="flex items-baseline justify-between gap-3">
                <dt className="text-muted-foreground">مبلغ انتقال</dt>
                <dd className="shrink-0 tabular">
                  <Money rial={form.data.amount} withUnit />
                </dd>
              </div>

              <div className="flex items-baseline justify-between gap-3">
                <dt className="text-muted-foreground">کارمزد</dt>
                <dd className="shrink-0 tabular">
                  <Money rial={form.data.fee} withUnit />
                </dd>
              </div>

              <div className="flex items-baseline justify-between gap-3 border-t border-border pt-2">
                <dt className="min-w-0 truncate">برداشت از {from.name}</dt>
                <dd className="shrink-0 font-medium tabular">
                  <Money rial={debit} withUnit />
                </dd>
              </div>

              <div className="flex items-baseline justify-between gap-3">
                <dt className="min-w-0 truncate">واریز به {to.name}</dt>
                <dd className="shrink-0 font-medium tabular">
                  <Money rial={form.data.amount} withUnit />
                </dd>
              </div>

              {remaining !== null && (
                <div className="flex items-baseline justify-between gap-3 border-t border-border pt-2">
                  <dt className="text-muted-foreground">مانده مبدأ پس از انتقال</dt>
                  <dd
                    className={cn('shrink-0 font-medium tabular', remaining < 0 && 'text-danger')}
                  >
                    <Money rial={remaining} withUnit />
                  </dd>
                </div>
              )}

              {remaining !== null && remaining < 0 && (
                <p className="text-2xs text-warning">این انتقال مانده حساب مبدأ را منفی می‌کند.</p>
              )}
            </dl>
          )}
        </form>

        <SheetFooter className="flex-row justify-end gap-2 border-t border-border p-5">
          <Button type="button" variant="ghost" onClick={close}>
            انصراف
          </Button>
          <Button type="submit" form="transfer-form" disabled={form.processing}>
            {form.processing ? 'در حال ثبت…' : 'ثبت انتقال'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}

/**
 * Label above, control, then error or hint beneath — design-system rule 8, and the
 * reason every field on this form has somewhere for its own message to land.
 */
function Field({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  hint?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error ? (
        // `role="alert"` and a stable id the control points at with `aria-describedby`.
        // Without both, a screen-reader user hears "combobox, invalid" and is never told
        // what was wrong — the same silence, one layer down, that this whole rewrite
        // exists to remove.
        <p id={`${id}-error`} role="alert" className="text-xs text-danger">
          {error}
        </p>
      ) : hint ? (
        <p className="text-2xs text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}

/**
 * An account picker that shows what is in the account.
 *
 * Choosing where money comes from without seeing the balance is the wrong way round,
 * and the balance is already in the payload — it costs nothing to put it in the list.
 */
function AccountSelect({
  id,
  value,
  options,
  invalid,
  onChange,
}: {
  id: string;
  value: number | null;
  options: AccountRow[];
  invalid: boolean;
  onChange: (id: number) => void;
}) {
  return (
    <Select
      value={value === null ? undefined : String(value)}
      onValueChange={(next) => onChange(Number(next))}
    >
      <SelectTrigger
        id={id}
        aria-invalid={invalid}
        aria-describedby={invalid ? `${id}-error` : undefined}
        className="w-full"
      >
        <SelectValue placeholder="انتخاب کنید" />
      </SelectTrigger>

      {/* Three deliberate departures from the kit's defaults:

          `popper` and a width pinned to the trigger — every other Select in the app
          picks from short labels, so the content could size itself freely; these options
          carry a name *and* a balance, and left to itself the list grew past the sheet
          and hung over the page behind it.

          `bg-card` — the popover token is 86% opaque, which is fine over a page and not
          fine over a form: opened above the «به حساب» field, the list and the field's
          own error occupied the same pixels.

          Grouped by kind, in `ACCOUNT_KINDS` order — the page spends its whole layout
          arguing that a treasurer reads صندوق → بانک → کارتخوان, and then handed them an
          alphabetical list the moment they went to act on it. */}
      <SelectContent
        dir="rtl"
        position="popper"
        className="w-(--radix-select-trigger-width) min-w-0 bg-card"
      >
        {groupByKind(options).map((group) => (
          <SelectGroup key={group.kind.type}>
            <SelectLabel>{group.kind.label}</SelectLabel>
            {group.accounts.map((account) => (
              <SelectItem key={account.id} value={String(account.id)} textValue={account.name}>
                <span className="flex w-full items-baseline justify-between gap-4">
                  <span className="truncate">{account.name}</span>
                  <span className="shrink-0 text-2xs text-muted-foreground">
                    <Money rial={account.balance.value} />
                  </span>
                </span>
              </SelectItem>
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  );
}
