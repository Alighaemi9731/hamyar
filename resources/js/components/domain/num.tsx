import { cn } from '@/lib/utils';
import { toPersianDigits } from '@/lib/digits';
import { useTenantSettings } from '@/hooks/use-tenant-settings';

export interface NumProps {
  value: number | string;
  /**
   * "prose" — Persian digits, natural width. Counts, quantities, sentences.
   * "table" — Latin tabular digits so columns line up. Tables, invoices, reports.
   * "ltr"   — an inherently-LTR identifier: IMEI, phone, barcode, serial.
   */
  variant?: 'prose' | 'table' | 'ltr';
  /** Group thousands. On by default for numbers, off for identifiers. */
  grouped?: boolean;
  className?: string;
}

/**
 * The single place digits are rendered (design-system rule 4).
 *
 * The three variants exist because Persian UIs genuinely need all three, and picking
 * per-site is how a codebase ends up with mixed digit styles in one table:
 *
 *   «۳ دستگاه در انتظار قطعه»       prose  → Persian digits
 *   quantity column in an invoice   table  → Latin, tabular, aligned
 *   IMEI 356938035643809            ltr    → Latin, LTR-isolated, never grouped
 *
 * An IMEI rendered in Persian digits cannot be read back to a customer over the
 * phone or typed into HAMTA, which is why "ltr" ignores the tenant digit setting.
 */
export function Num({ value, variant = 'prose', grouped, className }: NumProps) {
  const settings = useTenantSettings();

  const shouldGroup = grouped ?? variant !== 'ltr';

  const raw = typeof value === 'number' ? value : Number(value);
  const isNumeric = Number.isFinite(raw);

  let text = shouldGroup && isNumeric ? raw.toLocaleString('en-US') : String(value);

  if (variant === 'prose' && settings.digits === 'fa') {
    text = toPersianDigits(text);
  }

  if (variant === 'ltr') {
    return (
      <span className={cn('ltr-value tabular font-mono', className)} dir="ltr">
        {text}
      </span>
    );
  }

  return (
    <span className={cn(variant === 'table' && 'tabular whitespace-nowrap', className)}>
      {/* Isolated for the same reason as <Money/>: a leading minus is bidi-neutral and
          would otherwise jump to the far side of the number in RTL text. */}
      <bdi>{text}</bdi>
    </span>
  );
}
