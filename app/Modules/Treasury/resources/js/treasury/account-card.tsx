import { Link } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { ShareBar, sharePercent } from '@/components/domain/share-bar';
import { StatusBadge } from '@/components/domain/status-badge';
import { cn } from '@/lib/utils';

import { type AccountRow, kindOf } from './types';

interface AccountCardProps {
  account: AccountRow;
  /** Total across every money-holding account, for the share bar. */
  total: number;
  className?: string;
}

/**
 * One place the shop's money sits.
 *
 * ## Name first, figure second
 *
 * Somebody scanning this grid is looking for *an account* — «بانک ملت» — and only then
 * reading what is in it. So the name leads and the balance follows, rather than the
 * other way round. The balance is deliberately smaller than the page total: the whole
 * outranks its parts, and the previous version of this screen had that backwards.
 *
 * ## Every card is the same skeleton
 *
 * Four rows, always: identity, balance, share, reconciliation. Nothing is conditional on
 * the account's state — a settled account gets a quiet tick where a busy one gets a
 * badge, rather than the row disappearing. Grid rows size themselves independently, so
 * a card that grows only when it has something to say puts its neighbour's share bar out
 * of register; that was measured at 28px before the rows were fixed.
 *
 * ## The type is not repeated here
 *
 * Cards are grouped under a heading that names their kind, so printing «بانک» on the
 * card as well is noise. The icon carries it for anyone scanning a single card out of
 * context.
 */
export function AccountCard({ account, total, className }: AccountCardProps) {
  const kind = kindOf(account.type);
  const balance = account.balance.value;
  const unreconciled = account.unreconciled.value;

  const negative = balance < 0;
  const percent = sharePercent(balance, total);

  // The bank behind a card terminal, or the bank an account is held at — but only when
  // the name does not already say it. «بانک ملت — جاری اصلی» does not need «بانک ملت»
  // underneath it.
  const subtitle =
    account.bank_name && !account.name.includes(account.bank_name) ? account.bank_name : null;

  return (
    <Link
      href={`/treasury/accounts/${account.id}`}
      className={cn(
        'group flex flex-col rounded-card border border-border bg-card p-5 shadow-low',
        'transition-colors hover:border-border-strong hover:bg-accent/40',
        'focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
        'active:bg-accent/60',
        className
      )}
    >
      {/* The icon leads the name rather than floating at the far edge: kind and identity
          are one fact, and splitting them across the card left the glyph orphaned. */}
      <div className="flex min-w-0 items-start gap-2.5">
        <kind.icon className="mt-1 size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="min-w-0">
          <span className="block truncate font-medium">{account.name}</span>
          {subtitle && (
            <span className="mt-0.5 block truncate text-2xs text-muted-foreground">{subtitle}</span>
          )}
        </span>
      </div>

      {/* The balance gets its own row rather than sharing one with the state.
          Side by side they wrapped on one card and not its neighbour, which put the
          share bars of two cards in the same grid row 28px out of register — measured at
          1024, the commonest laptop width. Three fixed rows, one skeleton, always. */}
      <p
        className={cn(
          'mt-4 text-lg font-semibold tracking-tight',
          negative && 'text-danger'
          // Zero deliberately keeps full ink weight. Muted, «۰» in Vazirmatn is a small
          // grey circle that reads as a bullet or a figure that failed to load — the
          // meaning is carried by «موجودی ندارد» on the line beneath, not by dimming
          // the number until it looks broken.
        )}
      >
        {/* The card's accessible name is its text content, and adjacent inline elements
            concatenate without a pause — it announced as «صندوق۷۰٬۰۰۰٬۰۰۰تومان…». These
            separators name each part instead, so it reads as a sentence. An `aria-label`
            would have been the other option, but it would mean formatting money outside
            `<Money/>`. */}
        <span className="sr-only">، مانده </span>
        <Money rial={balance} withUnit unitPlacement="block" />
      </p>

      {/* Rendered for every card whenever a total exists, so the block keeps one shape
          across the grid — an empty track for a zero or overdrawn account rather than a
          missing row that would shorten the card. A bar cannot express "less than
          nothing", and the figure above is already red when it needs to be. */}
      {total > 0 && (
        <div className="mt-3">
          <ShareBar value={balance} total={total} tone={negative ? 'danger' : 'brand'} />
          <p className="mt-1.5 text-2xs text-muted-foreground">
            {negative ? (
              'بیش از موجودی برداشت شده'
            ) : balance === 0 ? (
              'موجودی ندارد'
            ) : (
              <>
                <Num value={percent} />٪ از موجودی کل
              </>
            )}
          </p>
        </div>
      )}

      {/*
        The reconciliation row, always present so every card is one skeleton.

        Only the state that wants attention takes colour. A filled green pill on the
        three accounts that need nothing was the loudest object on an otherwise
        near-monochrome page, and it meant "no action here" — colour spent on the
        default. Amber is now the only chip on the screen, which is what makes it
        findable (ADR 0008: colour is reserved for things you can act on).

        A third state matters: an account with no balance and nothing outstanding has
        nothing *to* reconcile, and calling that «مغایرت‌گیری کامل» is false assurance on
        a terminal nobody has checked.
      */}
      <div className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-border pt-3">
        <span className="sr-only">، </span>

        {unreconciled !== 0 ? (
          <>
            <StatusBadge status="unreconciled" className="text-2xs" />
            {/* Absolute: the badge already says these entries are outstanding, and a
                minus sign here reads as "negative money" rather than "credits not yet
                ticked off". */}
            <Money
              rial={Math.abs(unreconciled)}
              withUnit
              className="text-2xs text-muted-foreground"
            />
          </>
        ) : (
          <span className="inline-flex items-center gap-1.5 text-2xs text-muted-foreground">
            <CheckIcon className="size-3 shrink-0" aria-hidden />
            {balance === 0 ? 'چیزی برای مغایرت‌گیری نیست' : 'مغایرت‌گیری کامل'}
          </span>
        )}
      </div>
    </Link>
  );
}
