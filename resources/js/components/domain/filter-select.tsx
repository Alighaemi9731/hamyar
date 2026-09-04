import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export interface FilterSelectProps {
  /** The dimension, as the accessible name: «دسته», «انبار». Not drawn — see below. */
  label: string;
  /** The value currently applied, or `null` for "no filter". Numbers are ids. */
  value: string | number | null;
  options: { value: string; label: string }[];
  /** The "no filter" row, and what the closed control says when nothing is applied. */
  allLabel: string;
  onChange: (value: string | null) => void;
  className?: string;
}

/** Radix refuses an empty item value, so "no filter" travels as a sentinel. */
const ALL = 'all';

/**
 * One filter dimension with too many values for chips — a category list, a brand list,
 * a warehouse — as a compact select for `FilterBar`'s `children` slot.
 *
 * ## No caption above it
 *
 * Four registers drew this as a captioned field in a grid above the table («دسته» over a
 * select reading «همه دسته‌ها»), which says the dimension twice and stands 68px tall
 * beside a 40px search box. In the bar the *all* row names the dimension — «همه دسته‌ها»
 * is legible as "category, unfiltered" on its own — and the caption becomes the
 * control's accessible name instead of a second line of ink.
 *
 * ## Why this and not more chips
 *
 * `FilterBar`'s chip groups are for a handful of states a reader scans at once. A shop's
 * category tree or brand list is open-ended; forty chips is a wall, and the sheet below
 * `md` would be a page of them. A select is the honest control for a long list.
 */
export function FilterSelect({
  label,
  value,
  options,
  allLabel,
  onChange,
  className,
}: FilterSelectProps) {
  return (
    <Select
      value={value === null ? ALL : String(value)}
      onValueChange={(next) => onChange(next === ALL ? null : next)}
    >
      <SelectTrigger aria-label={label} className={cn('w-auto min-w-36', className)}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent dir="rtl">
        <SelectItem value={ALL}>{allLabel}</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
