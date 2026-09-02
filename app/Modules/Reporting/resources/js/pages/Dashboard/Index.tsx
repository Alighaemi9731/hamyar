import { Head, Link, usePage } from '@inertiajs/react';
import {
  AlertTriangleIcon,
  ArrowLeftIcon,
  type LucideIcon,
  PackagePlusIcon,
  ReceiptIcon,
  ShoppingCartIcon,
  UserPlusIcon,
  WrenchIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { BarChart } from '@/components/domain/bar-chart';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { UsageMeter } from '@/components/domain/usage-meter';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { cn } from '@/lib/utils';
import type { MoneyValue, SharedProps } from '@/types';

interface TodayWidget {
  revenue: MoneyValue;
  invoice_count: number;
  /** Absent — not zero — when the viewer may not see margin. */
  profit?: MoneyValue;
  margin_percent?: number;
}

interface TrendPoint {
  date: string;
  jalali: string;
  revenue: number;
  profit?: number;
}

interface RepairsWidget {
  total: number;
  statuses: { status: string; label: string; count: number }[];
}

interface AbandonedWidget {
  count: number;
  oldest: { id: number; code: string; device: string; party_name: string | null; days: number }[];
}

interface ChequeSide {
  overdue_count: number;
  overdue_total: MoneyValue;
  due_count: number;
  due_total: MoneyValue;
}

interface ChequesWidget {
  issued: ChequeSide;
  received: ChequeSide;
  soonest: {
    id: number;
    direction: string;
    party_name: string | null;
    amount: MoneyValue;
    due_date: string;
    overdue: boolean;
  }[];
}

interface InstallmentsWidget {
  count: number;
  total: MoneyValue;
  worst: {
    id: number;
    plan_number: string;
    party_name: string | null;
    outstanding: MoneyValue;
    days_late: number;
  }[];
}

interface LowStockWidget {
  count: number;
  lines: {
    variant_id: number;
    product_name: string;
    variant_name: string;
    on_hand: number;
    threshold: number;
    is_out: boolean;
  }[];
}

interface Props {
  today: TodayWidget | null;
  trend: TrendPoint[] | null;
  repairs: RepairsWidget | null;
  abandoned: AbandonedWidget | null;
  cheques: ChequesWidget | null;
  installments: InstallmentsWidget | null;
  low_stock: LowStockWidget | null;
  shows_profit: boolean;
  can: {
    sell: boolean;
    intake_repair: boolean;
    add_party: boolean;
    purchase: boolean;
    reports: boolean;
  };
}

/**
 * The front page.
 *
 * ## Every card is a door
 *
 * A dashboard whose numbers cannot be clicked makes the reader open the sidebar and
 * find the screen themselves, which is the moment they stop using the dashboard. So
 * each card names the action it wants and links to the screen that takes it.
 *
 * ## Null means "not yours to see"; zero means "nothing due"
 *
 * A widget the server sent as `null` is not rendered at all — the shop's plan does not
 * include it or this user has no permission. A widget with zeros renders and says so,
 * because «هیچ چکی سررسید ندارد» is the answer somebody came here for.
 *
 * ## Nothing here is a second source of truth
 *
 * Each figure is produced by the module that owns it (see `DashboardWidgets`), so a
 * number here and the same number on its own screen come from one query, not two.
 */
export default function DashboardIndex({
  today,
  trend,
  repairs,
  abandoned,
  cheques,
  installments,
  low_stock: lowStock,
  shows_profit: showsProfit,
  can,
}: Props) {
  const nothingVisible =
    today === null &&
    repairs === null &&
    cheques === null &&
    installments === null &&
    lowStock === null;

  const chequesOverdue =
    cheques === null
      ? 0
      : cheques.issued.overdue_total.value + cheques.received.overdue_total.value;

  return (
    <AppShell title="داشبورد" actions={<QuickActions can={can} />}>
      <Head title="داشبورد" />

      {nothingVisible ? (
        <EmptyState
          variant="permission"
          title="چیزی برای نمایش روی این داشبورد نیست"
          description="دسترسی‌های حساب شما شامل هیچ‌کدام از بخش‌های خلاصه نمی‌شود. اگر باید آمار فروش یا تعمیرات را ببینید، از مدیر فروشگاه بخواهید دسترسی‌تان را تغییر دهد."
        />
      ) : (
        <div className="space-y-14 sm:space-y-16">
          <UsageStrip />

          {today ? (
            <TodayBand
              today={today}
              trend={trend}
              showsProfit={showsProfit}
              canSeeReports={can.reports}
            />
          ) : null}

          <Attention
            cheques={cheques}
            chequesOverdue={chequesOverdue}
            installments={installments}
            abandoned={abandoned}
            lowStock={lowStock}
          />

          <div className="grid gap-4 lg:grid-cols-2">
            {repairs ? (
              <Card title="تعمیرات در جریان" href="/repairs/board" linkLabel="تخته کارها">
                {repairs.total === 0 ? (
                  <Quiet>هیچ دستگاهی روی میز نیست.</Quiet>
                ) : (
                  <ul className="space-y-2">
                    {repairs.statuses.map((row) => (
                      <li key={row.status} className="flex items-center gap-3">
                        <span className="w-32 shrink-0 text-sm text-muted-foreground">
                          {row.label}
                        </span>
                        {/* A bar rather than a bare number: the shape of the queue is
                            the point — twelve waiting on parts is a supplier problem,
                            twelve on the bench is a staffing one. */}
                        <span className="h-2 flex-1 overflow-hidden rounded-pill bg-muted">
                          <span
                            className="block h-full rounded-pill bg-brand"
                            style={{
                              width: `${repairs.total === 0 ? 0 : Math.round((row.count / repairs.total) * 100)}%`,
                            }}
                          />
                        </span>
                        <span className="w-8 shrink-0 text-end text-sm font-semibold tabular">
                          <Num value={row.count} />
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ) : null}

            {cheques ? (
              <Card title="چک‌های این هفته" href="/cheques" linkLabel="همه چک‌ها">
                {cheques.soonest.length === 0 ? (
                  <Quiet>این هفته چکی سررسید نمی‌شود.</Quiet>
                ) : (
                  <ul className="divide-y">
                    {cheques.soonest.map((cheque) => (
                      <li key={cheque.id} className="flex items-center justify-between gap-3 py-2">
                        <span className="min-w-0">
                          <span className="block truncate text-sm">
                            {cheque.party_name ?? 'بدون طرف حساب'}
                          </span>
                          <span
                            className={cn(
                              'text-xs',
                              cheque.overdue ? 'text-danger' : 'text-muted-foreground'
                            )}
                          >
                            {cheque.direction === 'issued' ? 'پرداختی' : 'دریافتی'}
                            {cheque.overdue ? ' · سررسید گذشته' : ''}
                          </span>
                        </span>
                        <Money rial={cheque.amount.value} withUnit className="shrink-0 text-sm" />
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ) : null}

            {installments ? (
              <Card title="اقساط عقب‌افتاده" href="/installments/collections" linkLabel="میز وصول">
                {installments.worst.length === 0 ? (
                  <Quiet>هیچ قسطی عقب نیفتاده است.</Quiet>
                ) : (
                  <ul className="divide-y">
                    {installments.worst.map((row) => (
                      <li key={row.id} className="flex items-center justify-between gap-3 py-2">
                        <span className="min-w-0">
                          <span className="block truncate text-sm">
                            {row.party_name ?? 'بدون طرف حساب'}
                          </span>
                          <span className="text-xs text-danger">
                            <Num value={row.days_late} /> روز تأخیر
                          </span>
                        </span>
                        <Money rial={row.outstanding.value} withUnit className="shrink-0 text-sm" />
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ) : null}

            {lowStock ? (
              <Card title="موجودی رو به اتمام" href="/inventory/low-stock" linkLabel="فهرست کامل">
                {lowStock.count === 0 ? (
                  <Quiet>هیچ کالایی به حد سفارش نرسیده است.</Quiet>
                ) : (
                  <ul className="divide-y">
                    {lowStock.lines.map((line) => (
                      <li
                        key={line.variant_id}
                        className="flex items-center justify-between gap-3 py-2"
                      >
                        <span className="min-w-0">
                          <span className="block truncate text-sm">{line.product_name}</span>
                          <span className="truncate text-xs text-muted-foreground">
                            {line.variant_name}
                          </span>
                        </span>
                        <span
                          className={cn(
                            'shrink-0 text-sm tabular',
                            line.is_out ? 'font-semibold text-danger' : 'text-warning'
                          )}
                        >
                          {line.is_out ? (
                            'ناموجود'
                          ) : (
                            <>
                              <Num value={line.on_hand} /> / <Num value={line.threshold} />
                            </>
                          )}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ) : null}

            {abandoned ? (
              <Card title="دستگاه‌های رسوبی" href="/repairs?status=abandoned" linkLabel="همه">
                {abandoned.count === 0 ? (
                  <Quiet>دستگاه رسوبی ندارید.</Quiet>
                ) : (
                  <>
                    <p className="mb-3 flex items-center gap-2 text-sm text-warning">
                      <AlertTriangleIcon className="size-4 shrink-0" aria-hidden />
                      <span>
                        <Num value={abandoned.count} /> دستگاه آماده تحویل مانده و کسی نیامده است.
                      </span>
                    </p>
                    <ul className="divide-y">
                      {abandoned.oldest.map((ticket) => (
                        <li
                          key={ticket.id}
                          className="flex items-center justify-between gap-3 py-2"
                        >
                          <span className="min-w-0">
                            <span className="block truncate text-sm">
                              {ticket.device || ticket.code}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                              {ticket.party_name ?? 'بدون طرف حساب'}
                            </span>
                          </span>
                          <span className="shrink-0 text-sm tabular text-muted-foreground">
                            <Num value={ticket.days} /> روز
                          </span>
                        </li>
                      ))}
                    </ul>
                  </>
                )}
              </Card>
            ) : null}
          </div>
        </div>
      )}
    </AppShell>
  );
}

/* ------------------------------------------------------------------ today -- */

/**
 * The one figure this screen exists to show, and the month it sits inside.
 *
 * ## Why the four tiles are gone
 *
 * The dashboard opened with today's sales, today's profit, overdue instalments and overdue
 * cheques as four equal-weight `StatCard`s. Four numbers stated at the same size, in a row,
 * comparing none of them — and two of the four were not about today at all, so "how did we
 * do today" and "what is late" were interleaved and neither read first.
 *
 * Takings are the anchor because it is the question a shop opens this page to ask. Profit
 * and the invoice count sit under it as facts *about* that figure rather than beside it as
 * rivals, and the two overdue tiles moved to {@see Attention}, where being late is the
 * thing they have in common.
 *
 * ## The chart is beside the figure, not below it
 *
 * A day's takings mean nothing alone — «۴۲ میلیون» is good or bad only against the month it
 * sits in. They were two separate blocks with a card boundary between them, which made the
 * comparison something the reader had to do rather than something the layout did.
 *
 * Split only from `xl`, for the reason the treasury summary records: at 1024 the sidebar
 * appears in the same breakpoint, and a nine-digit toman figure at 40px needs about 300px
 * of column.
 */
function TodayBand({
  today,
  trend,
  showsProfit,
  canSeeReports,
}: {
  today: TodayWidget;
  trend: TrendPoint[] | null;
  showsProfit: boolean;
  canSeeReports: boolean;
}) {
  const hasTrend = trend !== null && trend.length > 0;

  return (
    <section className="reveal rounded-card border border-border bg-card p-6 shadow-low sm:p-8">
      <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)] xl:gap-12">
        <div className="min-w-0">
          <h2 className="text-sm text-muted-foreground">فروش امروز</h2>

          {/* On a quiet morning this is a lone «۰» at 40px — a dot, not a number — and
              the only readable large figure on the page becomes the 30-day total beside
              it, so the hierarchy inverts. The zero stays truthful and keeps its slot; it
              just stops shouting. */}
          <p
            className={cn(
              'mt-2 font-display text-xl font-bold tracking-tight sm:text-2xl',
              today.invoice_count === 0 && 'text-muted-foreground'
            )}
          >
            <Money rial={today.revenue.value} withUnit unitPlacement="block" />
          </p>

          <p className="mt-3 text-xs text-muted-foreground">
            {today.invoice_count === 0 ? (
              'هنوز فاکتوری ثبت نشده است.'
            ) : (
              <>
                در <Num value={today.invoice_count} /> فاکتور
              </>
            )}
          </p>

          {today.profit ? (
            <div className="mt-5 border-t border-border pt-4">
              <p className="text-xs text-muted-foreground">سود امروز</p>
              <p className="mt-1 flex flex-wrap items-baseline gap-x-2">
                {/* `signed` so a loss reads as one. The margin beside it is what turns a
                    number into a judgement — ۴۲ میلیون on ۵٪ is a different day from
                    ۴۲ میلیون on ۳۰٪. */}
                <Money
                  rial={today.profit.value}
                  withUnit
                  signed
                  className="text-lg font-semibold"
                />
                <span className="text-xs text-muted-foreground">
                  حاشیه <Num value={today.margin_percent ?? 0} />٪
                </span>
              </p>
            </div>
          ) : null}
        </div>

        {hasTrend ? (
          <div className="min-w-0">
            {/*
              No heading above this. `BarChart` already renders its own label-and-total row
              — the series name on one side, the thirty-day total on the other — and a
              heading above it said almost the same words a second time, in the same size,
              directly over the top of it.
            */}
            <BarChart
              points={trend.map((point) => ({ label: point.jalali, value: point.revenue }))}
              title={showsProfit ? 'درآمد ۳۰ روز گذشته' : 'فروش ۳۰ روز گذشته'}
              height={128}
            />

            {canSeeReports && (
              <Link
                href="/reporting/sales"
                className="mt-3 inline-flex min-h-10 items-center text-xs text-brand hover:underline"
              >
                گزارش فروش
              </Link>
            )}
          </div>
        ) : null}
      </div>
    </section>
  );
}

/* -------------------------------------------------------------- attention -- */

interface AttentionRow {
  key: string;
  label: string;
  /** A `ReactNode` so the numbers in it go through `<Num>` and follow the tenant's digit
   *  setting, rather than through a local string helper that only this page has. */
  detail: ReactNode;
  amount: number | null;
  href: string;
}

/**
 * Everything that is late, in one place.
 *
 * ## Why it is one band and not four cards
 *
 * Overdue cheques, late instalments, abandoned devices and stock below its threshold were
 * four separate boxes among nine, each of which had to be found before it could be read.
 * They are the same question — *what is going wrong that I should act on* — and a shop
 * opening this page before unlocking the door is asking exactly that.
 *
 * ## The order is fixed, and money is first
 *
 * Not sorted by amount, because two of the four have no amount: a device nobody collected
 * and a line that ran out are counts. Ranking a count against a sum would be arithmetic
 * dressed as priority. So the order is stated instead — money that is late outranks goods
 * that are stuck, because money late is money that may never arrive, and a device on a
 * shelf is still a device on a shelf.
 *
 * ## Nothing wrong is a real state
 *
 * A shop with nothing overdue gets told so, once, quietly. The alternative — hiding the
 * band — makes "everything is fine" indistinguishable from "this section failed to load",
 * which is the confusion the empty-state rule exists to prevent.
 */
function Attention({
  cheques,
  chequesOverdue,
  installments,
  abandoned,
  lowStock,
}: {
  cheques: ChequesWidget | null;
  chequesOverdue: number;
  installments: InstallmentsWidget | null;
  abandoned: AbandonedWidget | null;
  lowStock: LowStockWidget | null;
}) {
  const overdueCheques =
    cheques === null ? 0 : cheques.issued.overdue_count + cheques.received.overdue_count;

  const rows: AttentionRow[] = [];

  if (cheques !== null && overdueCheques > 0) {
    rows.push({
      key: 'cheques',
      label: 'چک سررسیدگذشته',
      detail: (
        <>
          <Num value={cheques.issued.overdue_count} /> پرداختی ·{' '}
          <Num value={cheques.received.overdue_count} /> دریافتی
        </>
      ),
      amount: chequesOverdue,
      href: '/cheques',
    });
  }

  if (installments !== null && installments.count > 0) {
    rows.push({
      key: 'installments',
      label: 'قسط عقب‌افتاده',
      detail: (
        <>
          <Num value={installments.count} /> قسط
        </>
      ),
      amount: installments.total.value,
      href: '/installments/collections',
    });
  }

  if (abandoned !== null && abandoned.count > 0) {
    rows.push({
      key: 'abandoned',
      label: 'دستگاه رسوبی',
      detail: (
        <>
          <Num value={abandoned.count} /> دستگاه آماده تحویل، بدون مراجعه
        </>
      ),
      amount: null,
      href: '/repairs?status=abandoned',
    });
  }

  // `lowStock.count` and not `lines.length`: the lines are a sample the card below shows,
  // and counting them would report a smaller number than the truth.
  if (lowStock !== null && lowStock.count > 0) {
    rows.push({
      key: 'low-stock',
      label: 'کالای رو به اتمام',
      detail: (
        <>
          <Num value={lowStock.count} /> قلم زیر حد سفارش
        </>
      ),
      amount: null,
      href: '/inventory/low-stock',
    });
  }

  // Nothing to say, and no permission to say it about — the surrounding empty state
  // already covers that case.
  if (cheques === null && installments === null && abandoned === null && lowStock === null) {
    return null;
  }

  return (
    <section className="reveal reveal-delay-1">
      <h2 className="mb-4 font-display text-lg font-bold tracking-tight">معطل شما</h2>

      {rows.length === 0 ? (
        <p className="rounded-card border border-dashed border-border bg-surface/50 px-6 py-8 text-center text-sm text-muted-foreground">
          چیزی عقب نیفتاده است — نه چکی، نه قسطی، نه دستگاهی.
        </p>
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-card">
          {rows.map((row) => (
            <li key={row.key}>
              <Link
                href={row.href}
                className="flex min-h-[var(--density-row)] flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3.5 transition-colors hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium">{row.label}</span>
                  <span className="block truncate text-xs text-muted-foreground">{row.detail}</span>
                </span>

                {/* A count without a sum is not padded with a dash: three of these are
                    money and two are not, and inventing an amount for a device on a shelf
                    would be the ranking lie this list avoids. */}
                {row.amount !== null && (
                  <Money
                    rial={row.amount}
                    withUnit
                    className="shrink-0 font-semibold text-danger"
                  />
                )}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * A dashboard card: a heading, a link to the screen behind it, and the content.
 *
 * The link is part of the frame rather than something each card remembers to add —
 * that is what makes "every card is a door" true of all of them rather than most.
 */
function Card({
  title,
  href,
  linkLabel,
  children,
}: {
  title: string;
  href: string;
  linkLabel?: string;
  children: ReactNode;
}) {
  return (
    <section className="rounded-card border bg-card p-5">
      <div className="mb-4 flex items-baseline justify-between gap-3">
        <h2 className="font-semibold">{title}</h2>
        {linkLabel ? (
          <Link
            href={href}
            // `min-h-10`: these seven «فهرست کامل» links measured 17–24px tall — a line
            // of text, not a target — on the one screen a shop opens every morning.
            className="inline-flex min-h-10 items-center gap-1 text-sm text-brand hover:underline"
          >
            {linkLabel}
            {/* The arrow points the way the reader is going, which in RTL is left. */}
            <ArrowLeftIcon className="size-4" aria-hidden />
          </Link>
        ) : null}
      </div>
      {children}
    </section>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>;
}

/**
 * The one widget everybody gets — but only the doors this person can actually open.
 */
function QuickActions({ can }: { can: Props['can'] }) {
  const actions: { show: boolean; href: string; label: string; icon: LucideIcon }[] = [
    { show: can.sell, href: '/sales/pos', label: 'فروش جدید', icon: ShoppingCartIcon },
    { show: can.intake_repair, href: '/repairs/intake', label: 'پذیرش تعمیر', icon: WrenchIcon },
    { show: can.purchase, href: '/purchasing', label: 'ثبت خرید', icon: PackagePlusIcon },
    { show: can.add_party, href: '/crm/parties/create', label: 'مشتری جدید', icon: UserPlusIcon },
    { show: can.reports, href: '/reporting', label: 'گزارش‌ها', icon: ReceiptIcon },
  ].filter((action) => action.show);

  const [primary, ...rest] = actions;

  if (primary === undefined) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {/* Exactly one brand-filled button per view (design-system rule 7). */}
      <Button asChild>
        <Link href={primary.href}>
          <primary.icon className="size-4" aria-hidden />
          {primary.label}
        </Link>
      </Button>

      {rest.map((action) => (
        <Button key={action.href} asChild variant="outline">
          <Link href={action.href}>
            <action.icon className="size-4" aria-hidden />
            {action.label}
          </Link>
        </Button>
      ))}
    </div>
  );
}

/**
 * This month's credits, in one quiet line above everything else.
 *
 * Reads the shared `usage` prop rather than asking the server for anything, so the
 * dashboard — already this application's slowest page under load — pays nothing for it.
 *
 * Deliberately shows only what is worth looking at: the credits with usage on them, most
 * spent first, and at most four. A shopkeeper opening the front page wants to know whether
 * anything is about to stop them, not to read a specification of their plan.
 */
function UsageStrip() {
  const { usage } = usePage<SharedProps>().props;

  const meters = (usage?.meters ?? [])
    .filter((meter) => meter.limit !== null && meter.used > 0)
    .sort((a, b) => b.used / (b.limit ?? 1) - a.used / (a.limit ?? 1))
    .slice(0, 4);

  if (meters.length === 0) {
    return null;
  }

  return (
    <section className="rounded-card border border-border bg-surface px-5 py-4">
      <h2 className="mb-3 text-sm font-medium text-muted-foreground">سهمیهٔ این ماه</h2>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {meters.map((meter) => (
          <UsageMeter key={meter.key} meter={meter} compact />
        ))}
      </div>
    </section>
  );
}
