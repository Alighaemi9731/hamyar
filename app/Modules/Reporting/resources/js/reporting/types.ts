import type { MoneyValue } from '@/types';

/** The five ways the sales report groups the same question. */
export type Cut = 'daily' | 'monthly' | 'product' | 'brand' | 'salesperson';

export interface SalesRow {
  label: string;
  count: number;
  revenue: MoneyValue;
  /** Absent for a viewer who may not see what the shop paid. */
  cost?: MoneyValue;
  margin?: MoneyValue;
}

export interface SalesSummary {
  revenue: MoneyValue;
  invoice_count: number;
  returned_revenue: MoneyValue;
  cost?: MoneyValue;
  profit?: MoneyValue;
  margin_percent?: number;
}

export interface ReportPeriod {
  from: string;
  to: string;
  from_jalali: string;
  to_jalali: string;
}

export interface CutDefinition {
  key: Cut;
  label: string;
  /** The heading over the count column — «تعداد فاکتور» for a day, «تعداد» for a product. */
  unit: string;
  /** The heading over the label column. */
  heading: string;
}

/**
 * Presentation order, and the wording, in one place.
 *
 * Sitting beside the server contract rather than inside a page for the reason
 * `invoice/types.ts` and `treasury/types.ts` give: the sheet and the screen view render the
 * same cuts, and two lists that must agree are one list.
 */
export const SALES_CUTS: CutDefinition[] = [
  { key: 'daily', label: 'روزانه', unit: 'تعداد فاکتور', heading: 'تاریخ' },
  { key: 'monthly', label: 'ماهانه', unit: 'تعداد فاکتور', heading: 'ماه' },
  { key: 'product', label: 'بر اساس کالا', unit: 'تعداد', heading: 'کالا' },
  { key: 'brand', label: 'بر اساس برند', unit: 'تعداد', heading: 'برند' },
  { key: 'salesperson', label: 'بر اساس فروشنده', unit: 'تعداد', heading: 'فروشنده' },
];

/* ------------------------------------------------------------- profit -- */

export type ProfitCut = 'product' | 'brand' | 'imei';

export interface ProfitRow {
  label: string;
  count: number;
  product: string;
  invoice: string;
  sold_at: string;
  customer: string;
  revenue: MoneyValue;
  cost: MoneyValue;
  margin: MoneyValue;
}

export interface ProfitSummary {
  revenue: MoneyValue;
  cost: MoneyValue;
  profit: MoneyValue;
  margin_percent: number;
  invoice_count: number;
}

export const PROFIT_CUTS: { key: ProfitCut; label: string; heading: string }[] = [
  { key: 'product', label: 'بر اساس کالا', heading: 'کالا' },
  { key: 'brand', label: 'بر اساس برند', heading: 'برند' },
  { key: 'imei', label: 'هر دستگاه', heading: 'شناسه دستگاه' },
];

/* ---------------------------------------------------------------- tax -- */

export type TaxCut = 'monthly' | 'rate';

export interface TaxRow {
  label: string;
  taxable_base: MoneyValue;
  vat: MoneyValue;
  /** Monthly cut only. */
  invoices?: number;
  exempt_base?: MoneyValue;
  rounding?: MoneyValue;
  /** Rate cut only. */
  rate?: number;
  lines?: number;
}

export interface TaxTotals {
  taxable_base: MoneyValue;
  exempt_base: MoneyValue;
  vat: MoneyValue;
  rounding: MoneyValue;
  rows: number;
}

export const TAX_CUTS: { key: TaxCut; label: string }[] = [
  { key: 'monthly', label: 'ماهانه' },
  { key: 'rate', label: 'بر اساس نرخ' },
];

/* ---------------------------------------------------------- inventory -- */

export type InventoryCut = 'valuation' | 'dead';

export interface InventoryRow {
  label: string;
  kind: 'standard' | 'serialized';
  quantity: number;
  value: MoneyValue;
  unit_cost?: MoneyValue;
  idle_days?: number;
  last_out?: string;
}

export interface InventoryTotals {
  value: MoneyValue;
  device_value: MoneyValue;
  devices: number;
  items: number;
  lines: number;
}

export const INVENTORY_CUTS: { key: InventoryCut; label: string }[] = [
  { key: 'valuation', label: 'ارزش موجودی' },
  { key: 'dead', label: 'کالای راکد' },
];

/* --------------------------------------------------------- operations -- */

export interface OperationsRow {
  label: string;
  sent: number;
  failed: number;
  suppressed: number;
  queued: number;
  messages: number;
  segments: number;
  cost: MoneyValue;
}

export interface OperationsTotals {
  messages: number;
  segments: number;
  failed: number;
  cost: MoneyValue;
  templates: number;
}

export interface OperationsWallet {
  balance: MoneyValue;
  topups: MoneyValue;
  charges: MoneyValue;
  refunds: MoneyValue;
}

/* -------------------------------------------------------- technicians -- */

export interface TechnicianRow {
  technician: string;
  delivered: number;
  open: number;
  avg_turnaround_hours: number;
  /** Absent for a viewer who may not see what the shop paid. */
  parts_cost?: MoneyValue;
}

/* ---------------------------------------------------------- financial -- */

export type FinancialCut = 'aging' | 'cheques' | 'installments';
export type FinancialDirection = 'receivable' | 'payable';

export interface AgingRow {
  party_id: number;
  name: string;
  kind: string;
  total: MoneyValue;
  current: MoneyValue;
  days_60: MoneyValue;
  days_90: MoneyValue;
  older: MoneyValue;
  credit: MoneyValue;
}

export interface ChequeRow {
  due_date: string;
  incoming: MoneyValue;
  incoming_count: number;
  outgoing: MoneyValue;
  outgoing_count: number;
  net: MoneyValue;
  cleared: MoneyValue;
  bounced: MoneyValue;
}

export interface InstallmentRow {
  plan_number: string;
  party: string;
  sequence: number;
  due_at: string;
  amount: MoneyValue;
  collected: MoneyValue;
  outstanding: MoneyValue;
  status: string;
  overdue_days: number;
}

export interface ChequeOverdue {
  incoming: MoneyValue;
  incoming_count: number;
  outgoing: MoneyValue;
  outgoing_count: number;
}

export const FINANCIAL_TITLES: Record<FinancialCut, string> = {
  aging: 'مانده حساب طرف‌ها',
  cheques: 'تقویم چک‌ها',
  installments: 'دفتر اقساط',
};
