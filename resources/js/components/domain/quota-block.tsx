import { Link, router } from '@inertiajs/react';
import { OctagonAlertIcon } from 'lucide-react';

import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { QuotaBlockState } from '@/types';

export interface QuotaBlockProps {
  block?: QuotaBlockState | null;
  className?: string;
}

/**
 * What a shopkeeper sees the moment a monthly credit refuses their work.
 *
 * This is the commercial argument of the whole plan model, rendered. Somebody is mid-task
 * with a customer in front of them and the product has just said no; two sentences and one
 * button decide whether that becomes an upgrade or a support call.
 *
 * ## Why it lives in the shell rather than in each form
 *
 * The refusal arrives as an error-bag key AND this payload. About twenty-five forms in
 * this application render only the error keys they were written to expect, so relying on
 * the bag alone would make the message vanish on most of them — and a submit button that
 * silently does nothing, with a customer waiting, is precisely the failure CLAUDE.md's
 * "every form has a home for errors that belong to no field" rule exists to prevent.
 * Rendering once, in the shell, is right for every form including the ones nobody has
 * retrofitted yet.
 *
 * ## Why the button posts rather than links
 *
 * The upgrade is a purchase, and the price on it comes from the same `ProrationCalculator`
 * that writes the invoice (ADR 0006). Linking to `/billing` would make the operator find
 * the plan again and re-decide something they have already decided.
 *
 * When there is nowhere to upgrade to, or the user cannot buy, the button is REPLACED
 * rather than disabled — a dead button reads as a bug, while «از مدیر فروشگاه بخواهید» is
 * something the person in front of the screen can actually act on.
 */
export function QuotaBlock({ block, className }: QuotaBlockProps) {
  if (!block) {
    return null;
  }

  const next = block.next_plan;

  return (
    <div
      role="alert"
      className={cn(
        'mb-6 rounded-card border border-danger/40 bg-danger/5 p-4 text-sm',
        className,
      )}
    >
      <div className="flex items-start gap-3">
        <OctagonAlertIcon className="mt-0.5 size-5 shrink-0 text-danger" aria-hidden />

        <div className="min-w-0 grow">
          <p className="font-medium text-danger">{block.message}</p>

          {block.limit === null ? null : (
            <p className="mt-1 text-muted-foreground">
              {/*
                A standing capacity has no month, so it must not be labelled with one.
                `resets_at` is the signal already on the wire: it is null exactly for a
                Total-window metric, because nothing ever refills one — a seat or a live
                price-list link is freed by removing something, not by waiting.

                Without this branch the card contradicted itself in adjacent lines: the
                message (fixed on the server side in 0.16.0) correctly said «ظرفیت … تکمیل
                است», and then this line said «مصرف این ماه», promising a reset that never
                comes. Affects every Total metric — seats, storage, branches, price-list
                links, recurring templates, rental contracts.
              */}
              {block.resets_at === null ? 'ظرفیت مصرف‌شده: ' : 'مصرف این ماه: '}
              <Num value={block.used} /> از <Num value={block.limit} />
            </p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-3">
            {next && block.can_upgrade ? (
              <Button onClick={() => router.post(`/billing/subscribe/${next.code}`)}>
                ارتقا به {next.name} — {next.due.formatted}
              </Button>
            ) : null}

            {next && !block.can_upgrade ? (
              <p className="text-muted-foreground">
                برای ادامه، از مدیر فروشگاه بخواهید پلن را به {next.name} ارتقا دهد.
              </p>
            ) : null}

            {next ? null : (
              <p className="text-muted-foreground">
                برای افزایش ظرفیت با پشتیبانی تماس بگیرید.
              </p>
            )}

            <Link
              href="/billing"
              className="text-muted-foreground underline underline-offset-4"
            >
              مشاهدهٔ سهمیه‌ها
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
