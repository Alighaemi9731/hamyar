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
