import { Head, Link } from '@inertiajs/react';
import { AlertTriangleIcon, LayersIcon, WalletIcon } from 'lucide-react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { ShareBar, sharePercent } from '@/components/domain/share-bar';
import { Button } from '@/components/ui/button';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';

import { AccountCard } from '../../treasury/account-card';
import { TransferSheet } from '../../treasury/transfer-sheet';
import {
  type AccountGroup,
  type AccountRow,
  HEADING_TYPE_LABEL,
  type HeadingRow,
  groupByKind,
} from '../../treasury/types';
import type { MoneyValue } from '@/types';

interface Props {
  accounts: AccountRow[];
  headings: HeadingRow[];
  total: MoneyValue;
  /**
   * The shared Inertia error bag. Kept on the contract, but the transfer form drives its
   * own display from `useForm`'s copy of it — see `TransferSheet`, which renders every
   * key rather than the one this page used to.
   */
  errors: Record<string, string>;
}

/**
 * Where the shop's money is.
 *
 * ## The whole outranks its parts
 *
 * The page opens with one figure — everything the shop holds — and then breaks it down
 * by kind before showing a single account. The previous version printed that total as a
 * 15px grey caption under the title while six subordinate balances sat above it at
 * 28px, which put the reader's eye on the parts and left them adding up six-digit
 * numbers by hand.
 *
 * ## Places and headings are two lists, deliberately
 *
 * A till, a bank account and a کارتخوان hold balances somebody can count or check
 * against a statement. A sales or rent account is a classification — asking «چقدر توی
 * حساب اجاره داریم؟» is a category error, and a single table would invite exactly that
 * question every day until somebody acted on the answer.
 *
 * So headings appear below, in their own table, labelled «جمع» rather than «مانده»: a
 * total spent under a name, not money sitting anywhere. The two sections are given
 * visibly different treatments — cards against a table — so the distinction registers
 * before the sentence explaining it is read.
 *
 * ## Accounts are grouped by kind, most liquid first
 *
 * Cash is spendable now, bank money today, and card takings only once the acquirer
 * settles. That is the order a treasurer thinks in, so it is the order the page uses —
 * which is not the alphabetical `orderBy('type')` the server sends. Nothing about the
 * query changes; the grouping is presentation.
 *
 * ## Unreconciled money is a state, not a footnote
 *
 * A balance that is right with half its entries unticked is a shop that has checked
 * nothing. It is surfaced twice — once as an exposure line in the summary, once as a
 * badge on each card — because it is the figure that turns "the bank looks fine" into
 * "four of these have never been confirmed".
 */
export default function TreasuryIndex({ accounts, headings, total }: Props) {
  const toman = useTenantSettings().currency_display === 'toman';

  const groups = groupByKind(accounts);

  // Absolute, and deliberately: two accounts holding +100 and −100 of unchecked
  // movement represent 200 of unverified activity, not zero. Signing the sum would let
  // two unreconciled accounts cancel each other into an all-clear.
  const exposed = accounts.filter((account) => account.unreconciled.value !== 0);
  const exposure = exposed.reduce((sum, account) => sum + Math.abs(account.unreconciled.value), 0);

  return (
    <AppShell
      title="خزانه‌داری"
      actions={
        <>
          <Button variant="outline" asChild>
            <Link href="/treasury/close">بستن روز</Link>
          </Button>

          {/* The only brand-filled control on the screen. When the sheet is open its
              submit button takes that role, and this one is behind the overlay. */}
          {accounts.length >= 2 && <TransferSheet accounts={accounts} toman={toman} />}
        </>
      }
    >
      <Head title="خزانه‌داری" />

      {accounts.length === 0 ? (
        <EmptyState
          icon={WalletIcon}
          title="هیچ حساب فعالی ثبت نشده است"
          description="حساب‌های صندوق، بانک و کارتخوان هنگام راه‌اندازی فروشگاه ساخته می‌شوند. اگر این صفحه خالی است، با پشتیبانی تماس بگیرید — بدون دست‌کم یک صندوق، ثبت فروش نقدی ممکن نیست."
        />
      ) : (
        <div className="space-y-14 sm:space-y-16">
          <Summary
            total={total}
            groups={groups}
            accountCount={accounts.length}
            exposure={exposure}
            exposedCount={exposed.length}
          />

          <section className="reveal reveal-delay-1 space-y-8">
            {groups.map((group) => (
              <div key={group.kind.type}>
                {/* Just the name. The summary band above states this group's total,
                    its share and its account count — printing all three again 40px
                    below made the ranked breakdown look like it wasn't earning its
                    place. One owner per fact. */}
                <h2 className="mb-4 font-display text-lg font-bold tracking-tight">
                  {group.kind.label}
                </h2>

                {/* auto-fit rather than a fixed track count: a shop with one account
                    fills its row instead of stranding two thirds of it, which is what a
                    hard `lg:grid-cols-3` did to every newly opened shop.

                    The max stays `1fr`, deliberately. A fixed max (`24rem`) makes
                    `auto-fit` pack at that width rather than at the minimum, which cut
                    1024 down to one card per row with 224px of dead space beside it —
                    measured. The cure was worse than the wide-card complaint it was
                    meant to answer, and the card's own restructure (balance and state on
                    their own rows rather than pinned to opposite edges) is what actually
                    fixed how a stretched card reads. */}
                <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,17rem),1fr))] xl:[grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr))]">
                  {group.accounts.map((account) => (
                    <AccountCard key={account.id} account={account} total={total.value} />
                  ))}
                </div>
              </div>
            ))}

            {accounts.length === 1 && (
              <p className="text-xs text-muted-foreground">
                برای انتقال بین حساب‌ها دست‌کم به دو حساب نیاز دارید.
              </p>
            )}
          </section>

          <Headings headings={headings} />
        </div>
      )}
    </AppShell>
  );
}

/* ---------------------------------------------------------------- summary -- */

/**
 * The anchor: everything the shop holds, and where it is concentrated.
 *
 * The composition is a ranked breakdown rather than a row of stat tiles. Liquidity is a
 * comparison — how much is reachable now versus stuck in a terminal until settlement —
 * and four equal-weight tiles state four numbers without ever comparing them.
 */
function Summary({
  total,
  groups,
  accountCount,
  exposure,
  exposedCount,
}: {
  total: MoneyValue;
  groups: AccountGroup[];
  accountCount: number;
  exposure: number;
  exposedCount: number;
}) {
  return (
    <section className="reveal rounded-card border border-border bg-card p-6 shadow-low sm:p-8">
      {/*
        Two columns only from `xl`, not `lg`. At 1024 the sidebar appears in the same
        breakpoint that used to split this band, which left the total ~247px — and a
        nine-digit toman figure at 40px needs about 300. It overflowed its column and
        printed on top of the first composition row. `sm` is where the figure grows to
        40px, so the split has to wait until the column can actually hold it.
      */}
      <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:gap-14">
        <div className="min-w-0">
          <h2 className="text-sm text-muted-foreground">موجودی کل فروشگاه</h2>

          <p className="mt-2 font-display text-xl font-bold tracking-tight sm:text-2xl">
            <Money rial={total.value} withUnit unitPlacement="block" />
          </p>

          <p className="mt-3 text-xs text-muted-foreground">
            {/* One account is a design case, not an edge case: it is what every newly
                opened shop sees, and repeating the same figure twice at two sizes reads
                as a bug rather than a summary. */}
            {accountCount === 1 ? (
              'همهٔ موجودی فروشگاه در یک حساب است.'
            ) : (
              <>
                در <Num value={accountCount} /> حساب، از <Num value={groups.length} /> نوع
              </>
            )}
          </p>
        </div>

        {groups.length > 1 && (
          <div className="space-y-4">
            {groups.map((group) => (
              <div key={group.kind.type}>
                <div className="flex items-baseline justify-between gap-3">
                  <span className="flex min-w-0 items-center gap-2 text-sm">
                    <group.kind.icon
                      className="size-4 shrink-0 text-muted-foreground"
                      aria-hidden
                    />
                    <span className="truncate">{group.kind.label}</span>
                  </span>
                  <Money rial={group.total} className="shrink-0 text-sm font-medium" />
                </div>

                <ShareBar value={group.total} total={total.value} className="mt-2" />

                <p className="mt-1.5 text-2xs text-muted-foreground">
                  <Num value={sharePercent(group.total, total.value)} />٪ از کل ·{' '}
                  <Num value={group.accounts.length} /> حساب
                </p>
              </div>
            ))}
          </div>
        )}
      </div>

      {exposure > 0 && (
        <div className="mt-8 flex max-w-2xl items-start gap-3 rounded-control border border-warning/25 bg-warning/5 p-4">
          <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />

          {/* Stacked, not inline. Side by side the qualifier drifted to the far end of
              the strip and read as an unrelated fragment rather than as the sentence
              that stops «مغایرت‌گیری‌نشده» being mistaken for «اشتباه». */}
          <div className="min-w-0 space-y-1">
            <p className="text-sm">
              <Money rial={exposure} withUnit className="font-medium" /> در{' '}
              <Num value={exposedCount} /> حساب هنوز مغایرت‌گیری نشده است.
            </p>
            <p className="text-2xs text-muted-foreground">
              یعنی این مبلغ با صورت‌حساب تطبیق داده نشده — نه اینکه اشتباه است.
            </p>
          </div>
        </div>
      )}
    </section>
  );
}

/* --------------------------------------------------------------- headings -- */

function Headings({ headings }: { headings: HeadingRow[] }) {
  const columns: Column<HeadingRow>[] = [
    {
      key: 'name',
      header: 'سرفصل',
      cell: (row) => <span className="font-medium">{row.name}</span>,
    },
    {
      key: 'type',
      header: 'نوع',
      secondary: true,
      cell: (row) => (
        <span className="text-muted-foreground">{HEADING_TYPE_LABEL[row.type] ?? row.type}</span>
      ),
    },
    {
      key: 'total',
      header: 'جمع',
      numeric: true,
      // Held to its content width. Left to the table's own sizing the cell spanned
      // 237px for ~80px of ink, which stranded the figures at the far edge of the row
      // with a void between them and the column beside them.
      className: 'w-px whitespace-nowrap',
      // Latin digits: this is a column, and design-system rule 4 gives tables Latin
      // tabular figures so the numbers align on their stems.
      cell: (row) => <Money rial={row.total.value} digits="latin" />,
    },
  ];

  return (
    <section className="reveal reveal-delay-2">
      <div className="mb-4">
        <h2 className="font-display text-lg font-bold tracking-tight">سرفصل‌ها</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          این‌ها جای نگهداری پول نیستند؛ عنوانی هستند که پول زیر آن شمرده می‌شود — برای همین «جمع»
          دارند، نه «مانده».
        </p>
      </div>

      <DataTable
        columns={columns}
        rows={headings}
        rowKey={(row) => row.id}
        caption="فهرست سرفصل‌های حسابداری و جمع هر کدام"
        empty={
          <EmptyState
            icon={LayersIcon}
            title="هنوز سرفصلی ساخته نشده"
            description="سرفصل‌ها با ثبت اولین خرید، فروش یا هزینه ساخته می‌شوند."
          />
        }
      />
    </section>
  );
}
