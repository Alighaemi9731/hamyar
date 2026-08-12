import type { MoneyValue } from '@/types';

/**
 * What came back from a scan.
 *
 * One shape for both a handset and a quantity line, because the scan box does not know
 * which it is going to get and the basket has to accept either. `kind` is the
 * discriminator; the fields that only apply to one side are null on the other rather
 * than absent, so a reader can see the whole vocabulary in one place.
 */
export interface ScanCandidate {
  /** Stable across a result set — `unit:12` or `variant:7`. Also the basket's line key. */
  key: string;
  kind: 'unit' | 'variant';
  unit_id: number | null;
  variant_id: number;
  product_name: string;
  variant_name: string;
  description: string;
  /** Handsets only. */
  imei: string | null;
  status: string | null;
  /** False for a device that is sold, reserved, on a bench or written off. */
  sellable: boolean;
  /** Why it cannot be sold, in the shop's own words. Null when it can. */
  blocked_reason: string | null;
  condition_label: string | null;
  grade: string | null;
  warehouse_name: string | null;
  unit_price: MoneyValue;
  /** Null when the signed-in user lacks `inventory.view_cost`. */
  cost: MoneyValue | null;
  /** Quantity lines only; null for a handset, which is always exactly one. */
  on_hand: number | null;
}

/**
 * One row of the basket.
 *
 * Prices are integer rial (golden rule 2) and are held here, not looked up again: a
 * salesperson negotiating a discount changes this row, and re-reading the catalogue
 * price would undo them.
 */
export interface BasketLine {
  key: string;
  kind: 'unit' | 'variant';
  unit_id: number | null;
  variant_id: number | null;
  description: string;
  product_name: string;
  variant_name: string;
  imei: string | null;
  quantity: number;
  unit_price: number;
  discount_amount: number;
  warranty_months: number | null;
  /** What the shop had when this line was scanned. Null for a handset. */
  on_hand: number | null;
}

/**
 * One tender.
 *
 * `tendered_amount` is what the customer handed over and `amount` is what it settles;
 * they differ only when somebody pays cash with a round note. The difference is change,
 * computed for the screen and stored for the reprint.
 */
export interface BasketPayment {
  /** Local-only identity so React can key the rows; never sent to the server. */
  id: string;
  method: string;
  amount: number;
  tendered_amount: number | null;
  account_id: number | null;
  reference: string;
}

export interface AccountOption {
  id: number;
  name: string;
  type: string;
  is_default: boolean;
}

export interface PaymentMethodOption {
  value: string;
  label: string;
  needs_account: boolean;
  needs_reference: boolean;
}
