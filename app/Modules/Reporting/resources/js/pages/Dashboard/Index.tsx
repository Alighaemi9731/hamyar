import { Head, Link } from '@inertiajs/react';
import {
  AlertTriangleIcon,
  ArrowLeftIcon,
  BarChart3Icon,
  CreditCardIcon,
  FileTextIcon,
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
import { StatCard } from '@/components/domain/stat-card';
import { Button } from '@/components/ui/button';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { toPersianDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

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
  /*
   * `StatCard`'s hint is a string, so the digit style it would get from `<Num/>` has to
   * be applied by hand. Doing it through the tenant setting rather than a hardcoded
   * `fa-IR` locale matters: a shop set to Latin digits would otherwise get Persian ones
   * in the hint and Latin in the figure directly above it.
   */
  const settings = useTenantSettings();
  const count = (value: number): string => {
    const grouped = value.toLocaleString('en-US');

    return settings.digits === 'fa' ? toPersianDigits(grouped) : grouped;
  };

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
          icon={BarChart3Icon}
          title="چیزی برای نمایش روی این داشبورد نیست"
          description="دسترسی‌های حساب شما شامل هیچ‌کدام از بخش‌های خلاصه نمی‌شود. اگر باید آمار فروش یا تعمیرات را ببینید، از مدیر فروشگاه بخواهید دسترسی‌تان را تغییر دهد."
        />
      ) : (
        <div className="space-y-6">
          {/* The headline row: figures somebody acts on before lunch. */}
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {today ? (
              <StatCard
                label="فروش امروز"
                value={today.revenue.value}
                isMoney
                icon={ShoppingCartIcon}
                hint={`${count(today.invoice_count)} فاکتور`}
              />
            ) : null}

            {today?.profit ? (
              <StatCard
                label="سود امروز"
                value={today.profit.value}
                isMoney
                icon={BarChart3Icon}
                tone={today.profit.value >= 0 ? 'success' : 'danger'}
                hint={`حاشیه ${count(today.margin_percent ?? 0)}٪`}
              />
            ) : null}

            {installments ? (
              <StatCard
                label="اقساط عقب‌افتاده"
                value={installments.total.value}
                isMoney
                icon={CreditCardIcon}
                tone={installments.count > 0 ? 'danger' : 'neutral'}
                hint={`${count(installments.count)} قسط`}
              />
            ) : null}

            {cheques ? (
              <StatCard
                label="چک‌های سررسیدگذشته"
                value={chequesOverdue}
                isMoney
                icon={FileTextIcon}
                tone={chequesOverdue > 0 ? 'danger' : 'neutral'}
                hint={`${count(cheques.issued.overdue_count)} پرداختی · ${count(cheques.received.overdue_count)} دریافتی`}
              />
            ) : null}
          </div>

          {trend && trend.length > 0 ? (
            <Card
              title="فروش ۳۰ روز گذشته"
              href="/reporting/sales"
              linkLabel={can.reports ? 'گزارش فروش' : undefined}
            >
              <BarChart
                points={trend.map((point) => ({ label: point.jalali, value: point.revenue }))}
                title={showsProfit ? 'درآمد روزانه (بدون مالیات)' : 'فروش روزانه'}
                height={128}
              />
            </Card>
          ) : null}

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
            className="inline-flex items-center gap-1 text-sm text-brand hover:underline"
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
