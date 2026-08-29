import { Link } from '@inertiajs/react';
import { AlertTriangleIcon, OctagonAlertIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { UsageState } from '@/types';

export interface UsageBannerProps {
  usage?: UsageState;
  className?: string;
}

/**
 * One line above the page when a credit is running out, or the subscription has lapsed.
 *
 * Quiet by default and by design: it appears only when something needs attention, so its
 * presence carries information. A banner that is always there is furniture, and people
 * stop reading furniture — which is exactly what would happen if it announced "you have
 * used 12 of 300 invoices" every morning.
 *
 * Not dismissible, for the reason `AnnouncementBanner` gives: dismissal needs per-user
 * state to be honest about, and a banner that reappears on the next page load reads as
 * broken. It disappears on its own when the shop upgrades or the month turns.
 */
export function UsageBanner({ usage, className }: UsageBannerProps) {
  if (!usage || (usage.attention.length === 0 && !usage.plan.lapsed)) {
    return null;
  }

  const blocked = usage.meters.filter((meter) => meter.level === 'blocked');
  const critical = usage.plan.lapsed || blocked.length > 0;
  const Icon = critical ? OctagonAlertIcon : AlertTriangleIcon;

  return (
    <div
      role="status"
      className={cn(
        'mb-6 flex items-start gap-3 rounded-card border px-4 py-3 text-sm',
        critical
          ? 'border-danger/25 bg-danger/10 text-danger'
          : 'border-warning/25 bg-warning/10 text-warning',
        className,
      )}
    >
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />

      <p className="min-w-0 grow">
        {usage.plan.lapsed ? (
          <>
            اشتراک شما به پایان رسیده و سقف‌های پلن رایگان اعمال می‌شود. داده‌هایتان سر جای
            خودشان است.
          </>
        ) : (
          <>{describe(blocked.length > 0 ? blocked : nearingLimit(usage))}</>
        )}{' '}
        <Link href="/billing" className="font-medium underline underline-offset-4">
          {usage.plan.lapsed ? 'تمدید اشتراک' : 'مشاهده سهمیه‌ها'}
        </Link>
      </p>
    </div>
  );
}

function nearingLimit(usage: UsageState) {
  return usage.meters.filter((meter) => meter.level === 'warning' || meter.level === 'reached');
}

/**
 * Names the credits rather than counting them. «سهمیهٔ فاکتور فروش و ۲ مورد دیگر رو به
 * پایان است» tells a shopkeeper what to do; «۳ سهمیه» does not.
 */
function describe(meters: { label: string; level: string }[]): string {
  if (meters.length === 0) {
    return 'سهمیهٔ این ماه رو به پایان است.';
  }

  const first = meters[0];

  if (!first) {
    return 'سهمیهٔ این ماه رو به پایان است.';
  }

  const others = meters.length - 1;
  const verb = first.level === 'blocked' ? 'تمام شده است' : 'رو به پایان است';

  if (others === 0) {
    return `سهمیهٔ ${first.label} این ماه ${verb}.`;
  }

  return `سهمیهٔ ${first.label} و ${others} مورد دیگر این ماه ${verb}.`;
}
