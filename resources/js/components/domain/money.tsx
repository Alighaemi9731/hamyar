import { cn } from '@/lib/utils';
import { type CurrencyUnit, formatMoney } from '@/lib/money';
import { useTenantSettings } from '@/hooks/use-tenant-settings';

export interface MoneyProps {
  /** Amount in integer RIAL. Golden rule 2 — never a float, never toman. */
  rial: number;
  /** Override the tenant's display unit (an official invoice may force rial). */
  unit?: CurrencyUnit;
  /** Show the unit label ("تومان" / "ریال") after the number. */
  withUnit?: boolean;
  /**
   * Force digit style. Defaults to the tenant setting; tables and invoices pass
   * "latin" so columns align (design-system rule 4).
   */
  digits?: 'fa' | 'latin';
  /** Colour negative amounts as a debt. Off by default — not every negative is bad. */
  signed?: boolean;
  className?: string;
}

/**
 * The ONLY way money is rendered (design-system rule 5).
 *
 * Always tabular: without `tabular-nums` a column of prices in a Persian font shifts
 * left and right per row, which makes an invoice look wrong even when it is right.
 */
export function Money({
  rial,
  unit,
  withUnit = false,
  digits,
  signed = false,
  className,
}: MoneyProps) {
  const settings = useTenantSettings();

  const resolvedUnit = unit ?? settings.currency_display;
  const persian = (digits ?? settings.digits) === 'fa';

  const formatted = formatMoney(rial, resolvedUnit, persian);
  const label = resolvedUnit === 'toman' ? 'تومان' : 'ریال';

  return (
    <span
      className={cn(
        'tabular whitespace-nowrap',
        signed && rial < 0 && 'text-destructive',
        signed && rial > 0 && 'text-success',
        className
      )}
      // The machine-readable value is always rial, whatever we chose to display.
      data-rial={rial}
      title={`${formatMoney(rial, 'rial', false)} ریال`}
    >
      {/* The number is bidi-isolated, the unit label is not.
          A minus sign is a bidi-NEUTRAL character and Persian digits are class AN
          (not "strong"), so inside RTL text an unisolated "-۴۵۰٬۰۰۰" has its sign
          pushed to the far side and reads as "۴۵۰٬۰۰۰-". <bdi> keeps the sign glued
          to its number, while the label stays in RTL flow where Persian expects it
          («۱۲٬۵۰۰٬۰۰۰ تومان»). */}
      <bdi>{formatted}</bdi>
      {withUnit && <span className="ms-1 text-2xs text-muted-foreground">{label}</span>}
    </span>
  );
}
