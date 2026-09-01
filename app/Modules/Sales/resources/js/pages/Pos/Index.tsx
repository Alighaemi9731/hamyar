import { Head, router } from '@inertiajs/react';
import { FileTextIcon, SaveIcon, ShoppingCartIcon, Trash2Icon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { MoneyLadder, MoneyRow } from '@/components/domain/money-ladder';
import { Num } from '@/components/domain/num';
import { type PartyOption, PartyPicker } from '@/components/domain/party-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { FormErrors } from '@/components/domain/form-errors';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { type RoundingDirection, calculateTotals } from '@/lib/invoice-totals';
import { cn } from '@/lib/utils';

import { MoneyField } from '@/components/domain/money-field';
import { PaymentBox } from '../../pos/payment-box';
import { ScanBox, type ScanBoxHandle } from '../../pos/scan-box';
import { type TradeInDraft, TradeInBox } from '../../pos/trade-in-box';
import type {
  AccountOption,
  BasketLine,
  BasketPayment,
  PaymentMethodOption,
  ScanCandidate,
} from '../../pos/types';

interface ResumedInvoice {
  id: number;
  party: PartyOption | null;
  notes: string | null;
  discount_amount: number;
  shipping_amount: number;
  vat_applied: boolean;
  lines: Array<{
    key: string;
    kind: 'unit' | 'variant';
    unit_id: number | null;
    variant_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount_amount: number;
    warranty_months: number | null;
  }>;
  payments: Array<{
    method: string;
    amount: number;
    tendered_amount: number | null;
    account_id: number | null;
    reference: string | null;
  }>;
}

interface Props {
  invoice: ResumedInvoice | null;
  branch: { id: number; name: string };
  branches: Array<{ id: number; name: string }>;
  salesperson: { id: number; name: string } | null;
  accounts: AccountOption[];
  payment_methods: PaymentMethodOption[];
  vat: { rate: number; enabled: boolean };
  rounding: { step: number; direction: RoundingDirection };
  can: { view_profit: boolean; view_cost: boolean };
}

/**
 * The till.
 *
 * ## The one rule this screen is designed around
 *
 * A shopkeeper sees it a hundred times a day, and every one of those times starts the
 * same way: point the reader at a box. So the cursor is in the scan field on load, it
 * goes back there after every action, and a whole sale — scan, scan, take the cash, print
 * — can be completed without touching the mouse.
 *
 * ## The basket lives here, not on the server
 *
 * Scanning appends to local state; nothing is written until the sale is parked or sold.
 * The alternative, a request per line, means the operator waits on the network once per
 * item, which on a shop's connection is exactly the pause that loses to a paper notebook.
 * The cost is that the totals shown here are computed by a mirror of the server's
 * arithmetic (`@/lib/invoice-totals`) — see that file for why that duplication is
 * defensible and how the two are kept honest.
 *
 * ## Keyboard map
 *
 * | key | what it does |
 * |---|---|
 * | `F2` | back to the scan box, from anywhere |
 * | `Enter` (in scan box) | add the highlighted result |
 * | `↑` `↓` | move through results |
 * | `F9` | finalise the sale |
 * | `Esc` | clear the current search |
 *
 * `F9` rather than `Ctrl+Enter` because a till is often operated one-handed while the
 * other hand holds a phone, and because Enter already belongs to the scan box.
 */
export default function PosIndex({
  invoice,
  branch,
  branches,
  salesperson,
  accounts,
  payment_methods: methods,
  vat,
  rounding,
}: Props) {
  const settings = useTenantSettings();
  const toman = settings.currency_display === 'toman';

  const scanBox = useRef<ScanBoxHandle>(null);

  const [party, setParty] = useState<PartyOption | null>(invoice?.party ?? null);
  const [lines, setLines] = useState<BasketLine[]>(() => resumeLines(invoice));
  const [payments, setPayments] = useState<BasketPayment[]>(() => resumePayments(invoice));
  const [invoiceDiscount, setInvoiceDiscount] = useState(invoice?.discount_amount ?? 0);
  const [shipping, setShipping] = useState(invoice?.shipping_amount ?? 0);
  const [vatApplied, setVatApplied] = useState(invoice?.vat_applied ?? vat.enabled);
  const [tradeIn, setTradeIn] = useState<TradeInDraft | null>(null);
  const [notes, setNotes] = useState(invoice?.notes ?? '');
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const totals = useMemo(
    () =>
      calculateTotals({
        lines: lines.map((line) => ({
          key: line.key,
          quantity: line.quantity,
          unit_price: line.unit_price,
          discount_amount: line.discount_amount,
        })),
        discount_amount: invoiceDiscount,
        shipping_amount: shipping,
        vat_rate: vatApplied ? vat.rate : 0,
        rounding_step: rounding.step,
        rounding_direction: rounding.direction,
      }),
    [lines, invoiceDiscount, shipping, vatApplied, vat.rate, rounding]
  );

  // F2 anywhere returns to scanning, F9 sells. Bound on the window rather than the form
  // so they still work while the cursor sits in a discount field halfway down the panel.
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === 'F2') {
        event.preventDefault();
        scanBox.current?.focus();
      }

      if (event.key === 'F9') {
        event.preventDefault();
        submit('finalise');
      }
    }

    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
  });

  function addCandidate(candidate: ScanCandidate): void {
    setLines((current) => {
      const existing = current.find((line) => line.key === candidate.key);

      // A second scan of the same charger means two chargers. A second scan of the same
      // handset cannot mean two handsets — there is only one of it — so it is ignored
      // rather than added, which is also what stops a jittery reader double-firing.
      if (existing) {
        if (candidate.kind === 'unit') {
          return current;
        }

        return current.map((line) =>
          line.key === candidate.key ? { ...line, quantity: line.quantity + 1 } : line
        );
      }

      return [
        ...current,
        {
          key: candidate.key,
          kind: candidate.kind,
          unit_id: candidate.unit_id,
          variant_id: candidate.variant_id,
          description: candidate.description,
          product_name: candidate.product_name,
          variant_name: candidate.variant_name,
          imei: candidate.imei,
          quantity: 1,
          unit_price: candidate.unit_price.value,
          discount_amount: 0,
          warranty_months: null,
          on_hand: candidate.on_hand,
        },
      ];
    });
  }

  function updateLine(key: string, changes: Partial<BasketLine>): void {
    setLines((current) =>
      current.map((line) => (line.key === key ? { ...line, ...changes } : line))
    );
  }

  function submit(action: 'park' | 'quote' | 'finalise'): void {
    if (lines.length === 0 || processing) {
      return;
    }

    setProcessing(true);
    setErrors({});

    router.post(
      '/sales/pos',
      {
        branch_id: branch.id,
        party_id: party?.id ?? null,
        salesperson_id: salesperson?.id ?? null,
        // Rial on the wire, always. The display unit is a rendering choice; the API
        // speaks one currency unit and says which (golden rule 2).
        unit: 'rial',
        action,
        vat_applied: vatApplied,
        discount_amount: invoiceDiscount,
        shipping_amount: shipping,
        notes: notes.trim() || null,
        lines: lines.map((line) => ({
          unit_id: line.unit_id,
          variant_id: line.variant_id,
          description: line.description,
          quantity: line.quantity,
          unit_price: line.unit_price,
          discount_amount: line.discount_amount,
          warranty_months: line.warranty_months,
        })),
        trade_in:
          tradeIn === null
            ? null
            : {
                device_name: tradeIn.device_name.trim(),
                product_variant_id: tradeIn.variant?.id ?? null,
                imei1: tradeIn.imei1.replace(/\D/g, '') || null,
                grade: tradeIn.grade || null,
                agreed_price: tradeIn.agreed_price,
                hamta_ack: tradeIn.hamta_ack,
              },
        payments: payments.map((payment) => ({
          method: payment.method,
          amount: payment.amount,
          tendered_amount: payment.tendered_amount,
          account_id: payment.account_id,
          reference: payment.reference.trim() || null,
        })),
      },
      {
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => {
          setProcessing(false);
          scanBox.current?.focus();
        },
      }
    );
  }

  /*
  | The three keys this screen places itself, kept as they were — they render in a
  | position the cashier is already looking at.
  |
  | Everything ELSE the server can refuse now falls to <FormErrors> below. That gap was
  | not small: `PosSaleRequest` can return twelve keys this page could never show —
  | `payments.0.amount`, `payments.0.account_id`, `payments.0.tendered_amount`,
  | `lines.0.unit_price`, `lines.0.quantity`, `discount_amount`, `shipping_amount` and
  | more — and `PaymentBox` is not even passed the error bag. So a cashier who typed a
  | tendered amount below the total pressed F9 and the screen did not change: no message,
  | no highlight, nothing to debug, because from their side there was no error at all.
  |
  | That is the exact scenario CLAUDE.md's "a home for errors that belong to no field"
  | rule was written from, on the one screen where somebody is standing at a counter with
  | a customer waiting.
  */
  const blockingError = errors.lines ?? errors.branch_id ?? errors.invoice;

  return (
    <AppShell
      title={invoice ? 'ادامه فاکتور پیش‌نویس' : 'فروش'}
      actions={
        // `flex-wrap`, which `AppShell`'s own comment already warns about and this page
        // then re-created: the shell's row wraps, but a group inside it that does not
        // wrap runs straight off the edge. Three buttons here came to 411px inside a
        // 375px viewport and pushed the whole till sideways — on the screen a shop uses
        // most, and the one the smoke suite does not cover.
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={lines.length === 0 || processing}
            onClick={() => submit('park')}
          >
            <SaveIcon className="size-4" aria-hidden />
            ذخیره پیش‌نویس
          </Button>

          {/* A پیش‌فاکتور is a numbered document the customer takes away, which is what
              separates it from the parked draft beside it: a draft is the shop's own
              note to itself and never leaves the building. */}
          <Button
            type="button"
            variant="outline"
            disabled={lines.length === 0 || processing}
            onClick={() => submit('quote')}
          >
            <FileTextIcon className="size-4" aria-hidden />
            پیش‌فاکتور
          </Button>

          {/* The only brand-filled button on the screen (design-system rule 7). */}
          <Button
            type="button"
            disabled={lines.length === 0 || processing}
            onClick={() => submit('finalise')}
            data-testid="pos-finalise"
          >
            <ShoppingCartIcon className="size-4" aria-hidden />
            ثبت فاکتور
            <kbd className="ms-1 rounded-inner bg-primary-foreground/20 px-1 text-2xs">F9</kbd>
          </Button>
        </div>
      }
    >
      <Head title="فروش" />

      {blockingError && (
        <p
          role="alert"
          data-testid="pos-error"
          className="mb-4 rounded-control border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
          {blockingError}
        </p>
      )}

      {/* Everything the three keys above do not cover. `handled` names what already has
          a home so nothing is said twice — and it collapses nested keys, so listing
          `lines` covers `lines.0.quantity` and every sibling beneath it. */}
      <FormErrors
        errors={errors}
        handled={['lines', 'branch_id', 'invoice', 'trade_in']}
        className="mb-4"
      />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-4">
          <ScanBox
            ref={scanBox}
            partyId={party?.id ?? null}
            branchId={branch.id}
            onPick={addCandidate}
          />

          {lines.length === 0 ? (
            <EmptyState
              title="سبد خالی است"
              description="بارکد کالا یا IMEI دستگاه را اسکن کنید تا به فاکتور اضافه شود."
            />
          ) : (
            <div className="overflow-x-auto rounded-card border border-border">
              <table className="w-full text-sm">
                <thead className="bg-muted/50 text-2xs text-muted-foreground">
                  <tr>
                    <th scope="col" className="p-2 text-start font-medium">
                      کالا
                    </th>
                    <th scope="col" className="w-20 p-2 text-start font-medium">
                      تعداد
                    </th>
                    <th scope="col" className="w-32 p-2 text-start font-medium">
                      قیمت واحد
                    </th>
                    <th scope="col" className="w-28 p-2 text-start font-medium">
                      تخفیف
                    </th>
                    {/* `text-start` — physical right in RTL, where Latin numerals must
                        align so their units digits line up down the column. */}
                    <th scope="col" className="w-32 p-2 text-start font-medium">
                      جمع
                    </th>
                    <th scope="col" className="w-12 p-2">
                      <span className="sr-only">حذف</span>
                    </th>
                  </tr>
                </thead>

                <tbody>
                  {lines.map((line) => {
                    const computed = totals.lines.find((row) => row.key === line.key);

                    return (
                      <tr key={line.key} className="border-t border-border align-top">
                        <td className="p-2">
                          <span className="block font-medium">{line.product_name}</span>
                          <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                            <span>{line.variant_name}</span>
                            {line.imei && (
                              <>
                                <span aria-hidden>·</span>
                                <Num value={line.imei} variant="ltr" />
                              </>
                            )}
                          </span>
                        </td>

                        <td className="p-2">
                          {line.kind === 'unit' ? (
                            // A handset is one device, and the field is absent rather
                            // than disabled: a greyed-out input invites somebody to try.
                            <Num value={1} variant="table" />
                          ) : (
                            <Input
                              aria-label={`تعداد ${line.product_name}`}
                              dir="ltr"
                              inputMode="numeric"
                              // 40px, not 36. This is the field somebody changes with a
                              // customer standing there — the floor applies hardest here.
                              className="tabular"
                              value={String(line.quantity)}
                              onChange={(event) =>
                                updateLine(line.key, {
                                  quantity: Math.max(
                                    1,
                                    Number(toLatinDigits(event.target.value).replace(/\D/g, '')) ||
                                      1
                                  ),
                                })
                              }
                            />
                          )}
                        </td>

                        <td className="p-2">
                          <MoneyField
                            aria-label={`قیمت ${line.product_name}`}
                            toman={toman}
                            value={line.unit_price}
                            onChange={(rial) => updateLine(line.key, { unit_price: rial })}
                          />
                        </td>

                        <td className="p-2">
                          <MoneyField
                            aria-label={`تخفیف ${line.product_name}`}
                            toman={toman}
                            value={line.discount_amount}
                            onChange={(rial) => updateLine(line.key, { discount_amount: rial })}
                          />
                        </td>

                        <td className="p-2 text-start tabular">
                          <Money rial={computed?.line_total ?? 0} digits="latin" />
                          {line.on_hand !== null && line.quantity > line.on_hand && (
                            <span className="block text-2xs text-warning">بیش از موجودی انبار</span>
                          )}
                        </td>

                        <td className="p-2">
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`حذف ${line.product_name}`}
                            onClick={() => {
                              setLines((current) => current.filter((row) => row.key !== line.key));
                              scanBox.current?.focus();
                            }}
                          >
                            <Trash2Icon className="size-4" />
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <aside className="space-y-5">
          <div className="space-y-2">
            <Label htmlFor="pos-party">مشتری</Label>
            <PartyPicker id="pos-party" value={party} onChange={setParty} kind="customer" />
            <p className="text-2xs text-muted-foreground">
              برای فروش نقدی لازم نیست؛ برای نسیه و قسط الزامی است.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-2">
              <Label htmlFor="pos-discount">تخفیف فاکتور</Label>
              <MoneyField
                id="pos-discount"
                toman={toman}
                value={invoiceDiscount}
                onChange={setInvoiceDiscount}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="pos-shipping">هزینه ارسال</Label>
              <MoneyField id="pos-shipping" toman={toman} value={shipping} onChange={setShipping} />
            </div>
          </div>

          <Checkbox
            checked={vatApplied}
            onCheckedChange={(checked) => setVatApplied(checked === true)}
            label={
              <>
                مالیات بر ارزش افزوده (<Num value={vat.rate} variant="prose" />
                ٪)
              </>
            }
          />

          {/* One ladder for the whole block, total included: a second `<dl>` would derive a
              second figure axis, and the payable amount is the rung every other one adds up
              to. `withUnit` stays off the rungs — the fixed `9ch` track cannot hold a
              nine-digit figure plus «تومان», which is 98px of content. */}
          <MoneyLadder className="rounded-card border border-border p-4 text-sm">
            <Row label="جمع کالاها" rial={totals.subtotal} />
            {invoiceDiscount > 0 && <Row label="تخفیف فاکتور" rial={-invoiceDiscount} />}
            {totals.vat_amount > 0 && <Row label="مالیات" rial={totals.vat_amount} />}
            {shipping > 0 && <Row label="ارسال" rial={shipping} />}
            {totals.rounding_adjustment !== 0 && (
              <Row label="گرد کردن" rial={totals.rounding_adjustment} />
            )}

            <dt className="mt-1 border-t border-border pt-3 text-base font-semibold text-foreground">
              مبلغ قابل پرداخت
            </dt>
            <dd
              className="mt-1 border-t border-border pt-3 ps-6 text-start text-base font-semibold tabular"
              data-testid="pos-total"
            >
              {/*
                `unitPlacement="block"` rather than dropping the unit.

                Laddering these totals cost the payable amount its «تومان» on the first
                pass, which is the one figure on this screen a cashier says out loud to the
                person in front of them — a bare number is the wrong thing to read back.
                Inline it does not fit: the track is a fixed `9ch` and a nine-digit figure
                plus its unit is 98px. On its own line it fits, which is exactly the case
                `<Money>`'s docblock describes.
              */}
              <Money rial={totals.total} digits="latin" withUnit unitPlacement="block" />
            </dd>
          </MoneyLadder>

          {/* Sits with the payments, not the discounts: a trade-in is a tender. */}
          <TradeInBox
            value={tradeIn}
            onChange={setTradeIn}
            toman={toman}
            hasParty={party !== null}
            errors={errors}
          />

          <PaymentBox
            payments={payments}
            onChange={setPayments}
            methods={methods}
            accounts={accounts}
            total={totals.total}
            // What the customer's old phone already covers. The payment box shows what
            // is left after it, which is the figure the cashier is about to collect.
            tradedIn={tradeIn?.agreed_price ?? 0}
            toman={toman}
            hasParty={party !== null}
          />

          <div className="space-y-2">
            <Label htmlFor="pos-notes">توضیحات</Label>
            <Input
              id="pos-notes"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
            />
          </div>

          <p className="text-2xs text-muted-foreground">
            شعبه {branch.name}
            {branches.length > 1 && ' — برای تغییر شعبه، از نوار بالا شعبه را عوض کنید.'}
          </p>
        </aside>
      </div>
    </AppShell>
  );
}

/**
 * One line of the totals panel.
 *
 * `<bdi>` is inside `<Money/>` already; the wrapper here only handles the layout. A
 * negative figure (a discount, a downward rounding) is shown with its sign rather than
 * as a separate "less" column, because the column it would sit in has one number per row
 * and a reader adds them downwards.
 */
function Row({ label, rial }: { label: string; rial: number }) {
  // `MoneyRow` renders the two grid cells; the ladder around it owns the axis. This was
  // `flex justify-between`, which gives every figure its own right edge — the defect
  // measured at 99px of scatter on the invoice summary, here on the totals a shopkeeper
  // reads out loud to the person standing in front of them.
  return (
    <MoneyRow label={label} rial={rial} tone={rial < 0 ? 'text-muted-foreground' : undefined} />
  );
}

function resumeLines(invoice: ResumedInvoice | null): BasketLine[] {
  if (invoice === null) {
    return [];
  }

  return invoice.lines.map((line) => ({
    key: line.key,
    kind: line.kind,
    unit_id: line.unit_id,
    variant_id: line.variant_id,
    description: line.description,
    // A resumed draft carries the description it was saved with rather than a fresh
    // catalogue lookup: the invoice says what it sold, and re-reading the product name
    // would rewrite a line that has already been quoted to a customer.
    product_name: line.description,
    variant_name: '',
    imei: null,
    quantity: line.quantity,
    unit_price: line.unit_price,
    discount_amount: line.discount_amount,
    warranty_months: line.warranty_months,
    on_hand: null,
  }));
}

function resumePayments(invoice: ResumedInvoice | null): BasketPayment[] {
  if (invoice === null) {
    return [];
  }

  return invoice.payments.map((payment) => ({
    id: crypto.randomUUID(),
    method: payment.method,
    amount: payment.amount,
    tendered_amount: payment.tendered_amount,
    account_id: payment.account_id,
    reference: payment.reference ?? '',
  }));
}
