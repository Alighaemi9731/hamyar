import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * The single status → colour map for the whole product (design-system rule 5).
 *
 * Mapping colours ad hoc in a page is how "delivered" ends up green on one screen and
 * blue on another. Every status any module can show is registered here, with its
 * Persian label, so a shop owner learns one colour language:
 *
 *   success  money in, work finished     paid · cleared · delivered · in_stock
 *   warning  needs attention soon        due soon · awaiting approval · reserved
 *   danger   went wrong, act now         overdue · bounced · void · abandoned
 *   info     in flight, nothing to do    diagnosing · repairing · deposited
 *   neutral  inert                       draft · quote · written off
 */
export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

interface StatusDefinition {
  label: string;
  tone: StatusTone;
}

export const STATUS_MAP: Record<string, StatusDefinition> = {
  // --- sales invoices -------------------------------------------------------
  draft: { label: 'پیش‌نویس', tone: 'neutral' },
  final: { label: 'نهایی', tone: 'success' },
  void: { label: 'ابطال‌شده', tone: 'danger' },
  quote: { label: 'پیش‌فاکتور', tone: 'neutral' },
  paid: { label: 'تسویه‌شده', tone: 'success' },
  partially_paid: { label: 'پرداخت جزئی', tone: 'warning' },
  unpaid: { label: 'پرداخت‌نشده', tone: 'danger' },

  // --- serialized units (product_units) -------------------------------------
  in_stock: { label: 'موجود', tone: 'success' },
  reserved: { label: 'رزرو', tone: 'warning' },
  sold: { label: 'فروخته‌شده', tone: 'neutral' },
  in_repair: { label: 'در تعمیر', tone: 'info' },
  returned: { label: 'مرجوعی', tone: 'warning' },
  written_off: { label: 'ضایعات', tone: 'neutral' },

  // --- repair tickets -------------------------------------------------------
  queued: { label: 'در صف بررسی', tone: 'neutral' },
  diagnosing: { label: 'کارشناسی', tone: 'info' },
  awaiting_approval: { label: 'منتظر تأیید مشتری', tone: 'warning' },
  awaiting_parts: { label: 'منتظر قطعه', tone: 'warning' },
  repairing: { label: 'در حال تعمیر', tone: 'info' },
  ready: { label: 'آماده تحویل', tone: 'success' },
  delivered: { label: 'تحویل‌شده', tone: 'success' },
  rejected: { label: 'مرجوع بدون تعمیر', tone: 'neutral' },
  abandoned: { label: 'رسوبی', tone: 'danger' },

  // --- cheques --------------------------------------------------------------
  in_hand: { label: 'نزد صندوق', tone: 'neutral' },
  deposited: { label: 'خوابانده‌شده', tone: 'info' },
  cleared: { label: 'وصول‌شده', tone: 'success' },
  bounced: { label: 'برگشتی', tone: 'danger' },
  spent_to_third_party: { label: 'خرج‌شده به غیر', tone: 'neutral' },

  // --- installments ---------------------------------------------------------
  due_soon: { label: 'نزدیک سررسید', tone: 'warning' },
  overdue: { label: 'معوق', tone: 'danger' },
  settled: { label: 'تسویه‌شده', tone: 'success' },

  // --- treasury reconciliation ----------------------------------------------
  // `warning` rather than `danger`: an unreconciled entry is money nobody has checked
  // against a statement yet, not money that went wrong. Danger here would cry wolf on
  // every shop that has not done its weekly tick-off.
  //
  // There is deliberately no `reconciled` counterpart. "Everything is checked" is the
  // uninteresting default, and giving it an equally loud pill spent the palette on the
  // absence of news — on a six-account page, three green badges drowned the one amber
  // that mattered. The settled state is plain muted text with a tick; only the state
  // that wants attention gets colour.
  unreconciled: { label: 'مغایرت‌گیری‌نشده', tone: 'warning' },

  // --- subscriptions (platform) ---------------------------------------------
  pending: { label: 'در انتظار پرداخت', tone: 'warning' },
  failed: { label: 'ناموفق', tone: 'danger' },
  trialing: { label: 'دوره آزمایشی', tone: 'info' },
  active: { label: 'فعال', tone: 'success' },
  past_due: { label: 'پرداخت معوق', tone: 'warning' },
  canceled: { label: 'لغوشده', tone: 'neutral' },

  // --- HAMTA transfer -------------------------------------------------------
  // Keyed to the `product_units.hamta_status` column values, prefixed so they cannot
  // collide with a future transfer or ticket status that happens to be "pending".
  hamta_not_required: { label: 'همتا لازم ندارد', tone: 'neutral' },
  hamta_pending: { label: 'انتقال همتا انجام نشده', tone: 'danger' },
  hamta_done: { label: 'انتقال همتا انجام شد', tone: 'success' },
};

const TONE_CLASSES: Record<StatusTone, string> = {
  success: 'border-success/25 bg-success/10 text-success',
  warning: 'border-warning/25 bg-warning/10 text-warning',
  danger: 'border-danger/25 bg-danger/10 text-danger',
  info: 'border-info/25 bg-info/10 text-info',
  neutral: 'border-border bg-muted text-muted-foreground',
};

export interface StatusBadgeProps {
  status: string;
  /** Override the registered Persian label (rare — prefer registering it above). */
  label?: string;
  className?: string;
}

export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const definition = STATUS_MAP[status];

  // An unregistered status is a bug, but showing the raw key beats showing nothing:
  // it tells whoever sees it exactly which key to add to STATUS_MAP.
  const tone = definition?.tone ?? 'neutral';
  const text = label ?? definition?.label ?? status;

  return (
    <Badge
      variant="outline"
      data-status={status}
      className={cn('gap-1.5 rounded-full font-medium', TONE_CLASSES[tone], className)}
    >
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {text}
    </Badge>
  );
}
