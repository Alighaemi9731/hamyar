import { Head } from '@inertiajs/react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface Props {
  invoice: {
    number: string | null;
    status: string;
    issued_at: string | null;
    is_void: boolean;
    subtotal: MoneyValue;
    discount_amount: MoneyValue;
    vat_amount: MoneyValue;
    shipping_amount: MoneyValue;
    rounding_adjustment: MoneyValue;
    total: MoneyValue;
    paid_total: MoneyValue;
    outstanding: MoneyValue;
  };
  shop: { name: string; address: string | null; phone: string | null };
  customer: string | null;
  items: Array<{
    id: number;
    description: string;
    quantity: number;
    unit_price: MoneyValue;
    line_total: MoneyValue;
    warranty_months: number | null;
  }>;
}

/**
 * The invoice a customer sees after scanning the QR on their receipt.
 *
 * ## Not inside `AppShell`
 *
 * There is no signed-in user, no sidebar, no tenant switcher and no nav — every one of
 * those would be a link into a shop's private application, offered to whoever is holding
 * a piece of paper. This page is a single document on a plain ground.
 *
 * ## It shows the receipt, and only the receipt
 *
 * No IMEI, no cost, no margin, no balance, no other invoices. See
 * `PublicInvoiceController` for why each of those is left out; the short version is that
 * a signed link that leaks is a signed link somebody else can read.
 *
 * ## Sized for the phone that scanned it
 *
 * Nobody opens this on a desktop. Single column, generous type, and the total large
 * enough to read at arm's length.
 */
export default function PublicInvoice({ invoice, shop, customer, items }: Props) {
  return (
    <div className="min-h-dvh bg-muted/30 px-4 py-8">
      <Head title={invoice.number ? `فاکتور ${invoice.number}` : 'فاکتور'} />

      <div className="mx-auto max-w-lg space-y-5 rounded-card border border-border bg-background p-5 shadow-sm">
        <header className="space-y-1 border-b border-border pb-4 text-center">
          <h1 className="text-lg font-bold">{shop.name}</h1>
          {shop.address && <p className="text-2xs text-muted-foreground">{shop.address}</p>}
          {shop.phone && (
            <p className="text-2xs text-muted-foreground">
              <Num value={shop.phone} variant="ltr" />
            </p>
          )}
        </header>

        {invoice.is_void && (
          // Told plainly rather than 404'd: a customer holding a receipt for a cancelled
          // sale needs to know that is what they are holding.
          <p
            role="status"
            className="rounded-control border border-destructive/40 bg-destructive/5 px-3 py-2 text-center text-sm text-destructive"
          >
            این فاکتور ابطال شده است.
          </p>
        )}

        <dl className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
          <div className="flex items-baseline gap-2">
            <dt className="text-muted-foreground">شماره</dt>
            <dd className="tabular font-medium">{invoice.number}</dd>
          </div>
          <div className="flex items-baseline gap-2">
            <dt className="text-muted-foreground">تاریخ</dt>
            <dd>{formatJalali(invoice.issued_at)}</dd>
          </div>
          {customer && (
            <div className="flex items-baseline gap-2">
              <dt className="text-muted-foreground">خریدار</dt>
              <dd>{customer}</dd>
            </div>
          )}
        </dl>

        <ul className="divide-y divide-border border-y border-border">
          {items.map((item) => (
            <li key={item.id} className="flex items-baseline justify-between gap-3 py-3">
              <span className="min-w-0">
                <span className="block text-sm">{item.description}</span>
                <span className="text-2xs text-muted-foreground">
                  <Num value={item.quantity} variant="prose" /> ×{' '}
                  <Money rial={item.unit_price.value} digits="latin" />
                  {item.warranty_months !== null && (
                    <>
                      {' · '}گارانتی <Num value={item.warranty_months} variant="prose" /> ماه
                    </>
                  )}
                </span>
              </span>
              <span className="shrink-0 text-sm font-medium">
                <Money rial={item.line_total.value} digits="latin" />
              </span>
            </li>
          ))}
        </ul>

        <dl className="space-y-1 text-sm">
          <Row label="جمع کالاها" money={invoice.subtotal} />
          {invoice.discount_amount.value > 0 && (
            <Row label="تخفیف" money={invoice.discount_amount} />
          )}
          {invoice.vat_amount.value > 0 && <Row label="مالیات" money={invoice.vat_amount} />}
          {invoice.shipping_amount.value > 0 && (
            <Row label="ارسال" money={invoice.shipping_amount} />
          )}
          {invoice.rounding_adjustment.value !== 0 && (
            <Row label="گرد کردن" money={invoice.rounding_adjustment} />
          )}

          <div className="flex items-baseline justify-between border-t border-border pt-2 text-xl font-bold">
            <dt>مبلغ کل</dt>
            <dd data-testid="public-total">
              <Money rial={invoice.total.value} digits="latin" withUnit />
            </dd>
          </div>

          {invoice.outstanding.value > 0 && (
            <div className="flex items-baseline justify-between text-warning">
              <dt>باقی‌مانده</dt>
              <dd>
                <Money rial={invoice.outstanding.value} digits="latin" withUnit />
              </dd>
            </div>
          )}
        </dl>

        <p className="text-center text-2xs text-muted-foreground">
          این صفحه نسخه الکترونیکی فاکتور شماست.
        </p>
      </div>
    </div>
  );
}

function Row({ label, money }: { label: string; money: MoneyValue }) {
  return (
    <div className="flex items-baseline justify-between">
      <dt className="text-muted-foreground">{label}</dt>
      <dd>
        <Money rial={money.value} digits="latin" />
      </dd>
    </div>
  );
}
