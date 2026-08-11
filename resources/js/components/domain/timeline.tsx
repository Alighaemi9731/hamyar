import { Link } from '@inertiajs/react';
import {
  BanknoteIcon,
  BellIcon,
  type LucideIcon,
  MessageSquareIcon,
  PencilLineIcon,
  ReceiptIcon,
  SmartphoneIcon,
  SparklesIcon,
  TruckIcon,
  UndoIcon,
  WrenchIcon,
} from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { cn } from '@/lib/utils';
import { formatJalali } from '@/lib/jalali';

export interface TimelineItem {
  occurred_at: string;
  kind: string;
  title: string;
  description: string | null;
  /** Signed integer rial, or null for something that was not money. */
  amount: number | null;
  url: string | null;
  actor: string | null;
}

export interface TimelineProps {
  items: TimelineItem[];
  /** Modules whose contribution failed — named, so a gap is visible not silent. */
  failed?: string[];
  emptyTitle?: string;
  emptyDescription?: string;
  className?: string;
}

/**
 * Icon and tone per event kind.
 *
 * A map rather than per-page choices, for the same reason `STATUS_MAP` is one map: a
 * customer's history is read as a whole, and "purchase" being one colour here and
 * another on the next screen makes the reader re-learn it every time.
 */
const KINDS: Record<string, { icon: LucideIcon; tone: string }> = {
  purchase: { icon: TruckIcon, tone: 'text-info' },
  purchase_return: { icon: UndoIcon, tone: 'text-warning' },
  sale: { icon: ReceiptIcon, tone: 'text-success' },
  payment: { icon: BanknoteIcon, tone: 'text-success' },
  charge: { icon: BanknoteIcon, tone: 'text-warning' },
  device: { icon: SmartphoneIcon, tone: 'text-info' },
  repair: { icon: WrenchIcon, tone: 'text-info' },
  sms: { icon: MessageSquareIcon, tone: 'text-muted-foreground' },
  note: { icon: PencilLineIcon, tone: 'text-muted-foreground' },
  follow_up: { icon: BellIcon, tone: 'text-warning' },
  loyalty: { icon: SparklesIcon, tone: 'text-info' },
};

/**
 * The 360° customer timeline.
 *
 * One vertical rail, newest first, with every module's contribution in one order —
 * which is the whole point: a shop asking "what happened with this person" does not
 * think in modules, and a page with four separate lists makes them do the merging.
 *
 * Money keeps the ledger's sign convention (positive = they owe the shop more), and
 * the figure is drawn in the semantic colour rather than with a bare minus sign,
 * because a leading `-` in RTL prose jumps to the wrong side of the number.
 */
export function Timeline({
  items,
  failed = [],
  emptyTitle = 'هنوز رویدادی ثبت نشده',
  emptyDescription = 'خرید، فروش، پرداخت و یادداشت‌های این طرف حساب اینجا به ترتیب زمان می‌آید.',
  className,
}: TimelineProps) {
  if (items.length === 0) {
    return (
      <div className={className}>
        {failed.length > 0 && <FailureNotice failed={failed} />}
        <EmptyState title={emptyTitle} description={emptyDescription} />
      </div>
    );
  }

  return (
    <div className={className}>
      {failed.length > 0 && <FailureNotice failed={failed} />}

      <ol className="relative space-y-1">
        {/* The rail sits on the reading-start edge and is drawn behind the markers.
            `start-` keeps it on the right in RTL without a second rule. */}
        <span aria-hidden className="absolute inset-y-2 start-[15px] w-px bg-border" />

        {items.map((item, index) => {
          const kind = KINDS[item.kind] ?? { icon: PencilLineIcon, tone: 'text-muted-foreground' };
          const Icon = kind.icon;

          const body = (
            <>
              <span className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <span className="text-sm font-medium">{item.title}</span>
                {item.actor && (
                  <span className="text-2xs text-muted-foreground">— {item.actor}</span>
                )}
                <span className="ms-auto shrink-0 text-2xs text-muted-foreground tabular">
                  {formatJalali(item.occurred_at, { longMonth: true, withTime: true })}
                </span>
              </span>

              {item.description && (
                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                  {item.description}
                </span>
              )}

              {item.amount !== null && item.amount !== 0 && (
                <span
                  className={cn(
                    'mt-1 inline-block text-sm',
                    item.amount > 0 ? 'text-warning' : 'text-success'
                  )}
                >
                  {item.amount > 0 ? 'بدهکار ' : 'بستانکار '}
                  <Money rial={Math.abs(item.amount)} digits="latin" />
                </span>
              )}
            </>
          );

          return (
            <li key={`${item.occurred_at}-${index}`} className="relative flex gap-3">
              <span
                className={cn(
                  'relative z-10 mt-1.5 flex size-8 shrink-0 items-center justify-center rounded-full border border-border bg-card',
                  kind.tone
                )}
              >
                <Icon className="size-4" aria-hidden />
              </span>

              {item.url ? (
                <Link
                  href={item.url}
                  className="min-w-0 flex-1 rounded-control px-3 py-2 transition-colors hover:bg-accent"
                >
                  {body}
                </Link>
              ) : (
                <span className="min-w-0 flex-1 px-3 py-2">{body}</span>
              )}
            </li>
          );
        })}
      </ol>
    </div>
  );
}

/**
 * A module that could not answer.
 *
 * Shown rather than swallowed: a customer page quietly missing its repair history is
 * how somebody concludes a device was never brought in.
 */
function FailureNotice({ failed }: { failed: string[] }) {
  return (
    <p className="mb-4 rounded-control border border-warning/25 bg-warning/10 px-4 py-3 text-xs text-warning">
      بخشی از سوابق ({failed.join('، ')}) در دسترس نبود، بنابراین این فهرست کامل نیست.
    </p>
  );
}
