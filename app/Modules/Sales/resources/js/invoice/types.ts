import type { MoneyValue } from '@/types';

/**
 * The invoice payload, exactly as `InvoiceController::payload()` sends it.
 *
 * Lifted out of the page so the sections that render it can be split into their own
 * files without each one re-declaring a slice of the same shape. Nothing here is
 * derived or reshaped — the server's contract is the contract.
 */
export interface InvoiceItem {
  id: number;
  description: string;
  imei: string | null;
  quantity: number;
  unit_price: MoneyValue;
  discount_amount: MoneyValue;
  vat_amount: MoneyValue;
  line_total: MoneyValue;
  warranty_months: number | null;
  returned_quantity: number;
}

export interface InvoicePayment {
  id: number;
  method: string;
  method_label: string;
  account_name: string | null;
  amount: MoneyValue;
  tendered_amount: MoneyValue | null;
  change: MoneyValue;
  reference: string | null;
  received_at: string;
}

/**
 * Named rather than a `Record<string, MoneyValue>`.
 *
 * The loose map compiled, but every read of it was `MoneyValue | undefined` — so the
 * page had to guard figures that the server always sends, and a genuinely missing one
 * would have been indistinguishable from a typo in a key.
 */
export interface InvoiceTotals {
  subtotal: MoneyValue;
  discount_amount: MoneyValue;
  vat_amount: MoneyValue;
  shipping_amount: MoneyValue;
  rounding_adjustment: MoneyValue;
  total: MoneyValue;
  paid_total: MoneyValue;
  outstanding: MoneyValue;
}

export interface InvoiceReturn {
  id: number;
  number: string;
  total: MoneyValue;
  reason: string | null;
  returned_at: string;
}

export interface InvoiceTradeIn {
  device_name: string;
  imei1: string | null;
  agreed_price: MoneyValue;
  grade: string | null;
}

export interface Invoice {
  id: number;
  number: string | null;
  type: string;
  status: string;
  status_label: string;
  issued_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  notes: string | null;
  branch_name: string;
  salesperson_name: string | null;
  party: { id: number; name: string; mobile: string | null } | null;
  items: InvoiceItem[];
  payments: InvoicePayment[];
  totals: InvoiceTotals;
  returns: InvoiceReturn[];
  trade_in: InvoiceTradeIn | null;
  installment_plan: { id: number; number: string } | null;
}

export interface InvoiceProfit {
  revenue: MoneyValue;
  cost: MoneyValue;
  profit: MoneyValue;
  margin_percent: number;
  lines: Array<{
    id: number;
    description: string;
    revenue: MoneyValue;
    cost: MoneyValue;
    profit: MoneyValue;
  }>;
}

export interface InvoiceCommission {
  amount: MoneyValue;
  rate: number;
  salesperson: string | null;
}

export interface InvoiceAbilities {
  void: boolean;
  return: boolean;
  create: boolean;
}

/**
 * How the sale stands financially, expressed with keys `StatusBadge` already knows.
 *
 * Derived here rather than in each caller so the badge on the identity band and any
 * future one agree. It is a *view* of `totals`, never a second source of truth — the
 * server owns the figures and this only names the relationship between two of them.
 */
export function paymentStatus(totals: InvoiceTotals): 'paid' | 'partially_paid' | 'unpaid' {
  if (totals.outstanding.value <= 0) {
    return 'paid';
  }

  return totals.paid_total.value > 0 ? 'partially_paid' : 'unpaid';
}
