import { Head } from '@inertiajs/react';
import { PlusIcon, SearchIcon, SmartphoneIcon, TrendingUpIcon, WrenchIcon } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { BarChart } from '@/components/domain/bar-chart';
import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar } from '@/components/domain/filter-bar';
import { HistoryLink } from '@/components/domain/history-link';
import { ImeiInput } from '@/components/domain/imei-input';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { PageHeader } from '@/components/domain/page-header';
import { Num } from '@/components/domain/num';
import { Pagination } from '@/components/domain/pagination';
import { type PartyOption, PartyPicker } from '@/components/domain/party-picker';
import { type ReportPreset, ReportPresets } from '@/components/domain/report-presets';
import { FormErrors } from '@/components/domain/form-errors';
import { QuotaBlock } from '@/components/domain/quota-block';
import { ShareBar, sharePercent } from '@/components/domain/share-bar';
import { StatCard } from '@/components/domain/stat-card';
import { UsageBanner } from '@/components/domain/usage-banner';
import { UsageMeter } from '@/components/domain/usage-meter';
import { STATUS_MAP, StatusBadge } from '@/components/domain/status-badge';
import { type TimelineItem, Timeline } from '@/components/domain/timeline';
import { type UnitOption, UnitPicker } from '@/components/domain/unit-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { toPersianDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import { AppShell } from '@/layouts/app-shell';
import type { UsageMeterState } from '@/types';

/**
 * /design — the component gallery (dev-only route).
 *
 * The workflow rule from the hamyar-ui skill: a component appears HERE, with its
 * full state matrix, before it is used in a feature page. That makes visual review a
 * single page instead of a hunt through the app, and it is where you check RTL, dark
 * mode and the 390px/1280px breakpoints in one pass.
 *
 * When you add a component, add its states here too — default, hover, focus,
 * disabled, loading, error, and empty where the component has one.
 */
export default function DesignGallery() {
  return (
    <AppShell title="گالری دیزاین‌سیستم">
      <Head title="گالری دیزاین‌سیستم" />

      <p className="mb-14 max-w-2xl text-sm leading-relaxed text-muted-foreground">
        هر کامپوننت پیش از استفاده در صفحات محصول، اینجا با همه حالت‌هایش ثبت می‌شود. این صفحه فقط
        در محیط توسعه در دسترس است. برای بررسی: یک‌بار در حالت روشن و یک‌بار تیره، در عرض ۳۹۰ و ۱۲۸۰
        پیکسل.
      </p>

      <div className="space-y-6">
        <TokensSection />
        <MoneySection alt />
        <NumSection />
        <DateSection alt />
        <StatusSection />
        <ButtonSection alt />
        <CardSection />
        <PageHeaderSection alt />
        <FilterBarSection />
        <FormSection />
        <OverlaySection alt />
        <TableSection />
        <StatCardSection alt />
        <ShareBarSection />
        <QuotaSection />
        <FormErrorsSection alt />
        <BarChartSection />
        <ImeiSection alt />
        <DataTableSection />
        <PickerSection alt />
        <ConfirmAndPagingSection />
        <ReportPresetsSection />
        <PrintSection alt />
        <StateSection />
      </div>
    </AppShell>
  );
}

/* -------------------------------------------------------------------------- */

/**
 * Sections alternate ground rather than relying on borders — that alternation is the
 * primary separator in this visual language (ADR 0008). `alt` flips a section onto
 * `surface-muted`; the gallery alternates them so both grounds get reviewed.
 */
function Section({
  title,
  note,
  alt = false,
  children,
}: {
  title: string;
  note?: string;
  alt?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section
      className={cn(
        'rounded-card border border-border px-6 py-10 sm:px-10 sm:py-14',
        alt ? 'bg-surface-muted' : 'bg-surface'
      )}
    >
      {/* <bdi> isolates the heading from the surrounding RTL paragraph direction.
          Without it, a Latin title like "<Money/>" has its angle brackets reordered by
          the bidi algorithm and displays as "</Money>". `dir="auto"` on <bdi> picks the
          direction from the first strong character, so Persian titles stay RTL. */}
      <h2 className="mb-2 text-xl font-bold">
        <bdi>{title}</bdi>
      </h2>
      {note && (
        <p className="mb-10 max-w-2xl text-sm leading-relaxed text-muted-foreground">{note}</p>
      )}
      <div className="space-y-8">{children}</div>
    </section>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-3 sm:grid-cols-[11rem_1fr] sm:items-start">
      <span className="pt-2 text-xs text-muted-foreground">{label}</span>
      <div className="flex flex-wrap items-center gap-3">{children}</div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function TokensSection({ alt }: { alt?: boolean }) {
  const grounds = [
    { name: 'canvas', value: '#FFFFFF', className: 'bg-canvas text-ink' },
    { name: 'canvas-alt', value: '#F5F5F7', className: 'bg-canvas-alt text-ink' },
    { name: 'canvas-invert', value: '#000000', className: 'bg-canvas-invert text-white' },
    { name: 'ink', value: '#1D1D1F', className: 'bg-ink text-white' },
    { name: 'ink-soft', value: '#6E6E73', className: 'bg-ink-soft text-white' },
  ];

  /*
    `dark` is the step the token switches to under `.dark` — every one of these is
    unreadable on black at its light value, so the token itself is remapped in
    app.css rather than each call site adding a `dark:` variant. The swatch names
    both values because it is the one place in the app that prints a hex, and a
    swatch labelled #8A5A00 while rendering #E0A13A is documentation that lies.
  */
  const semantics = [
    { name: 'brand', value: '#0066CC', dark: '#409CFF', className: 'bg-brand text-white' },
    { name: 'success', value: '#0F7B3F', dark: '#4CC47F', className: 'bg-success text-white' },
    { name: 'warning', value: '#8A5A00', dark: '#E0A13A', className: 'bg-warning text-white' },
    { name: 'danger', value: '#B3261E', dark: '#FF6961', className: 'bg-danger text-white' },
    { name: 'label', value: '#FFD84D', dark: null, className: 'bg-label text-ink' },
  ];

  return (
    <Section
      alt={alt}
      title="توکن‌ها"
      note="رنگ فقط معنا را حمل می‌کند، نه تزئین را: آبی یعنی «می‌شود روی این کاری کرد»، و سه رنگ سمانتیک یعنی وضعیت مالی/کاری. بقیه رابط خنثی است. هیچ کد رنگ خامی در صفحات نوشته نمی‌شود."
    >
      <Row label="زمینه و متن">
        <div className="flex flex-wrap gap-2">
          {grounds.map((s) => (
            <div
              key={s.name}
              className={`flex h-20 w-28 flex-col justify-end rounded-control border border-border p-2.5 text-2xs ${s.className}`}
            >
              <span className="font-medium">{s.name}</span>
              <span className="ltr-value opacity-70" dir="ltr">
                {s.value}
              </span>
            </div>
          ))}
        </div>
      </Row>

      <Row label="یک آبی + سمانتیک">
        <div className="flex flex-wrap gap-2">
          {semantics.map((s) => (
            <div
              key={s.name}
              className={`flex h-20 w-28 flex-col justify-end rounded-control border border-border p-2.5 text-2xs ${s.className}`}
            >
              <span className="font-medium">{s.name}</span>
              <span className="ltr-value opacity-70" dir="ltr">
                {s.value}
              </span>
              {s.dark ? (
                <span className="ltr-value opacity-70" dir="ltr">
                  {s.dark} <span className="opacity-80">dark</span>
                </span>
              ) : null}
            </div>
          ))}
        </div>
      </Row>

      <Row label="تایپوگرافی">
        <div className="space-y-3">
          <p className="font-display text-3xl font-extrabold">استعداد ۸۰۰ — تیتر</p>
          <p className="font-display text-xl font-bold">استعداد ۷۰۰ — تیتر بخش</p>
          <p className="max-w-xl text-base leading-relaxed">
            وزیرمتن ۴۰۰ در اندازه ۱۷ پیکسل با ارتفاع خط ۱٫۶۵ — فارسی به فاصله سطر بیشتری از لاتین
            نیاز دارد و این قاعده دست‌نخورده مانده است.
          </p>
          <p className="tabular text-sm" dir="ltr">
            1,250,000 — tabular figures line up in a column
          </p>
        </div>
      </Row>

      <Row label="شکل">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex h-16 w-28 items-center justify-center rounded-pill border border-border bg-surface text-2xs">
            pill
          </div>
          <div className="flex h-16 w-28 items-center justify-center rounded-card border border-border bg-surface text-2xs">
            card 18px
          </div>
          <div className="flex h-16 w-28 items-center justify-center rounded-control border border-border bg-surface text-2xs">
            control 12px
          </div>
          <div className="flex h-16 w-28 items-center justify-center rounded-inner border border-border bg-surface text-2xs">
            inner 8px
          </div>
        </div>
      </Row>

      {/*
        The elevation ramp, all three steps side by side, because the middle one was
        missing and nobody could see that it was: dropdown, select and popover reached for
        Tailwind's `shadow-md` and the sheet for `shadow-lg`, which is a second ramp with
        different colour and spread running beside the tokens. Shown on the muted ground
        rather than white — a shadow this soft is invisible against `--color-canvas`, which
        is also why it is the last thing this system uses to signal depth.
      */}
      <Row label="عمق">
        <div className="flex w-full flex-wrap items-end gap-4 rounded-card bg-surface-muted p-6">
          <div className="flex h-16 w-32 items-center justify-center rounded-card bg-card text-2xs shadow-low">
            low — کارت
          </div>
          <div className="flex h-16 w-32 items-center justify-center rounded-card bg-card text-2xs shadow-mid">
            mid — منو
          </div>
          <div className="flex h-16 w-32 items-center justify-center rounded-card bg-card text-2xs shadow-high">
            high — دیالوگ
          </div>
          <span className="self-center text-2xs text-muted-foreground">
            عمق را اول اختلاف زمینه و فضای خالی می‌سازد؛ سایه آخرین ابزار است.
          </span>
        </div>
      </Row>

      <Row label="کروم شیشه‌ای">
        <div className="relative h-24 w-full max-w-md overflow-hidden rounded-card">
          {/* Something busy behind the panel, so the blur and saturate are visible.
              A flat fill would make the frosted effect impossible to review. */}
          <div className="absolute inset-0 bg-brand" />
          <div className="absolute inset-y-0 start-1/3 w-24 bg-warning" />
          <div className="absolute inset-y-0 end-8 w-16 bg-success" />
          <div className="glass absolute inset-x-0 top-0 flex h-12 items-center border-b px-4 text-2xs">
            .glass — نوار چسبان مات‌شیشه‌ای
          </div>
        </div>
      </Row>

      <Row label="حرکت">
        <div className="flex flex-wrap gap-3">
          {['reveal', 'delay-1', 'delay-2'].map((label, i) => (
            <div
              key={label}
              className={cn(
                'reveal flex h-16 w-28 items-center justify-center rounded-card border border-border bg-surface text-2xs',
                i === 1 && 'reveal-delay-1',
                i === 2 && 'reveal-delay-2'
              )}
            >
              {label}
            </div>
          ))}
          <span className="self-center text-2xs text-muted-foreground">
            تنها واژگان حرکتی: محو + بالا آمدن. با prefers-reduced-motion غیرفعال می‌شود.
          </span>
        </div>
      </Row>

      {/*
        The durations, as swatches that actually move.

        They were three inlined numbers before — `duration-100` on four overlays,
        `duration-200` on the sheet, `duration-500` on the share bar — so there was no
        place to see them together and no way to notice they disagreed. Hovering is the
        demo: the point of a duration token is how long it feels, which a hex-style swatch
        cannot show.
      */}
      <Row label="مدت و منحنی">
        <div className="flex w-full flex-wrap items-center gap-3">
          {(
            [
              ['fast', 'duration-(--duration-fast)', '۱۰۰ms — لایه‌ای که باز می‌شود'],
              ['base', 'duration-(--duration-base)', '۲۰۰ms — کشویی که مسافتی می‌رود'],
              ['slow', 'duration-(--duration-slow)', '۵۰۰ms — مقداری که جابه‌جا می‌شود'],
            ] as const
          ).map(([name, duration, hint]) => (
            <div
              key={name}
              className={cn(
                'group flex h-16 w-40 cursor-default flex-col items-center justify-center rounded-card border border-border bg-surface text-2xs transition-colors ease-(--ease-out) hover:bg-accent',
                duration
              )}
              title={hint}
            >
              <span className="font-medium">{name}</span>
              <span className="text-muted-foreground">{hint}</span>
            </div>
          ))}
          <span className="self-center text-2xs text-muted-foreground">
            یک منحنی: <code className="ltr-value">--ease-out</code>. روی کارت‌ها بروید تا تفاوت را
            ببینید.
          </span>
        </div>
      </Row>

      {/*
        Named z-index, listed rather than demonstrated: the whole value of these tokens is
        that the numbers are decided once and read in order, and a stack of coloured boxes
        would show the order without showing the names anybody actually types.

        Worth listing at all because the previous spelling — `--z-sticky` — was outside the
        namespace Tailwind v4 builds `z-` utilities from, so `z-sticky` generated no rule
        and the sticky header shipped with no stacking order at all.
      */}
      <Row label="ترتیب لایه‌ها">
        <ul className="space-y-1 text-2xs">
          {[
            ['z-sticky', '۲۰', 'سربرگ و نوار کناری چسبان'],
            ['z-drawer', '۴۰', 'کشوی منوی موبایل'],
            ['z-overlay', '۵۰', 'دیالوگ و شیت و پرده‌شان'],
            ['z-popover', '۶۰', 'منو، سلکت، پاپ‌اور، تولتیپ'],
            ['z-toast', '۷۰', 'اعلان‌ها — همیشه بالاترین'],
          ].map(([cls, value, use]) => (
            <li key={cls} className="flex flex-wrap items-baseline gap-2">
              <code className="ltr-value w-24 shrink-0">{cls}</code>
              <span className="w-8 shrink-0 tabular text-muted-foreground">{value}</span>
              <span className="text-muted-foreground">{use}</span>
            </li>
          ))}
        </ul>
      </Row>
    </Section>
  );
}

function MoneySection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="<Money/>"
      note="تنها راه نمایش پول. ورودی همیشه عدد صحیح ریال است؛ واحد نمایش (ریال/تومان) و شکل ارقام از تنظیمات فروشگاه می‌آید."
    >
      <Row label="پیش‌فرض فروشگاه">
        <Money rial={125_000_000} withUnit />
        <Money rial={0} withUnit />
        <Money rial={-4_500_000} withUnit signed />
      </Row>
      <Row label="ریال، ارقام لاتین">
        <Money rial={125_000_000} unit="rial" digits="latin" withUnit />
      </Row>
      <Row label="تومان، ارقام فارسی">
        <Money rial={125_000_000} unit="toman" digits="fa" withUnit />
      </Row>
      <Row label="ستون جدول (تراز)">
        <div className="w-40 space-y-0.5 text-end">
          <div>
            <Money rial={9_500_000} digits="latin" />
          </div>
          <div>
            <Money rial={125_000_000} digits="latin" />
          </div>
          <div>
            <Money rial={1_250_000_000} digits="latin" />
          </div>
        </div>
      </Row>
    </Section>
  );
}

function NumSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt
      title="<Num/>"
      note="سه حالت عمدی: متن (ارقام فارسی)، جدول (لاتینِ جدولی)، و شناسه‌های ذاتاً LTR مثل IMEI که هرگز فارسی نمی‌شوند — چون باید تلفنی خوانده و در همتا وارد شوند."
    >
      <Row label="prose">
        <span className="text-xs">
          <Num value={3} /> دستگاه در انتظار قطعه
        </span>
      </Row>
      <Row label="table">
        <Num value={1250} variant="table" />
        <Num value={98} variant="table" />
      </Row>
      <Row label="ltr (IMEI)">
        <Num value="356938035643809" variant="ltr" />
      </Row>
      <Row label="ltr (موبایل)">
        <Num value="09121234567" variant="ltr" />
      </Row>
    </Section>
  );
}

function DateSection({ alt }: { alt?: boolean }) {
  const [value, setValue] = useState<string | null>(new Date().toISOString());
  const [empty, setEmpty] = useState<string | null>(null);

  return (
    <Section
      alt={alt}
      title="<JDatePicker/>"
      note="ورودی و خروجی همیشه UTC است؛ جلالی فقط نمایش است. هفته از شنبه شروع می‌شود و جمعه رنگ متفاوت دارد."
    >
      <Row label="مقدار دارد">
        <div className="w-56">
          <JDatePicker value={value} onChange={setValue} />
        </div>
        <code className="ltr-value text-2xs text-muted-foreground" dir="ltr">
          {value ?? 'null'}
        </code>
      </Row>
      <Row label="خالی">
        <div className="w-56">
          <JDatePicker value={empty} onChange={setEmpty} placeholder="تاریخ سررسید" />
        </div>
      </Row>
      <Row label="خطا">
        <div className="w-56">
          <JDatePicker value={null} onChange={() => {}} invalid />
        </div>
        <span className="text-2xs text-destructive">تاریخ سررسید الزامی است.</span>
      </Row>
      <Row label="غیرفعال">
        <div className="w-56">
          <JDatePicker value={value} onChange={setValue} disabled />
        </div>
      </Row>
    </Section>
  );
}

function StatusSection({ alt }: { alt?: boolean }) {
  const groups: Array<{ label: string; keys: string[] }> = [
    { label: 'فاکتور', keys: ['draft', 'final', 'void', 'paid', 'partially_paid', 'unpaid'] },
    {
      label: 'واحد سریالی',
      keys: ['in_stock', 'reserved', 'sold', 'in_repair', 'returned', 'written_off'],
    },
    {
      label: 'تعمیر',
      keys: [
        'queued',
        'diagnosing',
        'awaiting_approval',
        'awaiting_parts',
        'repairing',
        'ready',
        'delivered',
        'rejected',
        'abandoned',
      ],
    },
    { label: 'چک', keys: ['in_hand', 'deposited', 'cleared', 'bounced', 'spent_to_third_party'] },
    { label: 'اقساط', keys: ['due_soon', 'overdue', 'settled'] },
    { label: 'همتا', keys: ['hamta_not_required', 'hamta_pending', 'hamta_done'] },
  ];

  return (
    <Section
      alt
      title="<StatusBadge/>"
      note={`نگاشت وضعیت→رنگ فقط یک‌جا تعریف می‌شود (${Object.keys(STATUS_MAP).length} وضعیت). رنگ‌دهی دستی در صفحه = باگ.`}
    >
      {groups.map((group) => (
        <Row key={group.label} label={group.label}>
          {group.keys.map((key) => (
            <StatusBadge key={key} status={key} />
          ))}
        </Row>
      ))}
      <Row label="وضعیت ثبت‌نشده">
        <StatusBadge status="some_unregistered_key" />
        <span className="text-2xs text-muted-foreground">
          کلید خام نمایش داده می‌شود تا معلوم باشد چه چیزی باید به STATUS_MAP اضافه شود.
        </span>
      </Row>

      <PaperIslandCase />
    </Section>
  );
}

/**
 * The regression case for the paper light island (design-system §1).
 *
 * A print sheet is ink on white in BOTH themes, so the dark theme's lifted semantic
 * steps are the wrong ones inside it — #4CC47F is 7.5:1 on #1D1D1F and 2.2:1 on white,
 * and a «وصول‌شده» stamp on an invoice went from readable to nearly invisible the moment
 * a shop switched to dark mode. `[data-paper]` restores the light steps, once, in
 * `app.css`.
 *
 * Rendering the SAME badges on both grounds is the point: in dark mode the two panels
 * must not match, and the paper one must stay legible. A contrast checker would catch
 * the regression; this catches it by eye, which is what gets looked at.
 *
 * Note the paper panel sets `data-paper` rather than only `bg-white` — faking a sheet
 * with `bg-white text-black` is precisely the bug, since the ground turns white while
 * every token inside stays on its dark step.
 */
function PaperIslandCase() {
  const keys = ['cleared', 'due_soon', 'overdue', 'deposited', 'sold'];

  return (
    <div className="grid gap-3 sm:grid-cols-[11rem_1fr] sm:items-start">
      <span className="pt-2 text-xs text-muted-foreground">روی کاغذ / روی صفحه</span>

      <div className="space-y-3">
        <div className="grid gap-4 md:grid-cols-2">
          <div className="rounded-card border border-border bg-background p-4">
            <p className="mb-3 text-2xs text-muted-foreground">
              زمینهٔ برنامه — در حالت تیره پله‌های روشن‌شده
            </p>
            <div className="flex flex-wrap items-center gap-3">
              {keys.map((key) => (
                <StatusBadge key={key} status={key} />
              ))}
            </div>
          </div>

          <div
            data-paper="a4"
            className="rounded-card border border-border bg-white p-4 text-black"
          >
            <p className="mb-3 text-2xs text-muted-foreground">
              داخل کاغذ ([data-paper]) — همان نشان‌ها، پله‌های تیرهٔ اصلی
            </p>
            <div className="flex flex-wrap items-center gap-3">
              {keys.map((key) => (
                <StatusBadge key={key} status={key} />
              ))}
            </div>
          </div>
        </div>

        <p className="max-w-2xl text-2xs leading-relaxed text-muted-foreground">
          کاغذ در هر دو تم سفید است، پس نشان‌های داخل آن باید به رنگ‌های تیرهٔ روی سفید برگردند. تم
          را تیره کنید: دو کادر بالا باید متفاوت به نظر برسند و کادر کاغذ باید خوانا بماند. اگر
          یکسان شدند، قاعدهٔ [data-paper] شکسته است.
        </p>
      </div>
    </div>
  );
}

/**
 * Card — the surface 141 sites were hand-rolling.
 *
 * Shown as a grid of every combination that has a caller, because the argument for the
 * component is not that a bordered box is hard to write: it is that the app contained
 * twenty-five spellings of the same box, three grounds, five padding scales, and
 * `border` beside `border border-border` — which are the same thing, since the base layer
 * already paints every element's border with `--border`.
 */
function CardSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="Card"
      note="سطح استاندارد: شعاع، حاشیهٔ مویی و فاصلهٔ داخلی یک‌جا. زمینه، فاصله، ارتفاع و حالت تعاملی از طریق variant انتخاب می‌شوند."
    >
      <Row label="زمینه">
        <div className="grid w-full gap-3 sm:grid-cols-3">
          <Card ground="card">
            <span className="text-2xs">ground=&quot;card&quot;</span>
          </Card>
          <Card ground="surface">
            <span className="text-2xs">ground=&quot;surface&quot;</span>
          </Card>
          <Card ground="none">
            <span className="text-2xs">ground=&quot;none&quot;</span>
          </Card>
        </div>
      </Row>

      <Row label="فاصلهٔ داخلی">
        <div className="grid w-full gap-3 sm:grid-cols-4">
          {(['none', 'sm', 'md', 'lg'] as const).map((padding) => (
            <Card key={padding} padding={padding}>
              <span className="text-2xs">padding=&quot;{padding}&quot;</span>
            </Card>
          ))}
        </div>
      </Row>

      <Row label="ارتفاع">
        <div className="grid w-full gap-3 rounded-card bg-surface-muted p-6 sm:grid-cols-2">
          <Card>
            <span className="text-2xs">پیش‌فرض — بدون سایه</span>
          </Card>
          <Card elevated>
            <span className="text-2xs">elevated — shadow-low</span>
          </Card>
        </div>
      </Row>

      {/* The interactive state matrix is the reason this variant exists rather than being
          left to each caller: hover, focus-visible and active are three separate
          decisions, and a card that is a link needs all three or it reads as decoration.
          Tab to it to see the focus ring — that is the state most often forgotten. */}
      <Row label="تعاملی">
        <div className="grid w-full gap-3 sm:grid-cols-2">
          <Card asChild interactive elevated>
            <a href="#card-demo">
              <span className="text-2xs">کارتی که خودش پیوند است — هاور، فوکوس و فشردن</span>
            </a>
          </Card>
          <Card asChild interactive>
            <button type="button" className="text-start">
              <span className="text-2xs">asChild روی دکمه</span>
            </button>
          </Card>
        </div>
      </Row>

      <Row label="کجا استفاده نشود">
        <p className="max-w-xl text-2xs text-muted-foreground">
          هشدارهای رنگی (خطا، اخطار) کارت نیستند؛ آن‌ها اعلان‌اند و رنگ زمینه و حاشیه‌شان معنا دارد.
          تا وقتی کامپوننت جدایی برایشان ساخته نشده، همان‌طور که هستند می‌مانند — افزودن prop رنگ به
          این کامپوننت، آن را به دو کار وامی‌دارد.
        </p>
      </Row>
    </Section>
  );
}

/**
 * PageHeader — the slot thirteen pages went around because it did not exist.
 *
 * Five passed `AppShell` no `title` at all and hand-rolled an `<h1>`; eight passed a title
 * and rendered a second one. Both are the same missing thing: nowhere to put an eyebrow, a
 * description, a back link or a row of badges, so a page builds its own header and the
 * shell's becomes a duplicate.
 *
 * Rendered here in a bordered box rather than bare, because a page header's whole job is
 * its relationship to the edges of the content column, and a specimen floating in a
 * gallery row shows none of that.
 */
function PageHeaderSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="PageHeader"
      note="عنوان صفحه، به‌علاوهٔ آنچه AppShell جا نداشت: پیش‌عنوان، توضیح، بازگشت و ردیف وضعیت. هر صفحه دقیقاً یک h1 — نوع AppShell این را اجبار می‌کند."
    >
      <Row label="کمینه">
        <div className="w-full rounded-card border border-border p-6">
          <PageHeader title="چک‌ها" headingLevel="h2" className="mb-0" />
        </div>
      </Row>

      <Row label="با اقدام">
        <div className="w-full rounded-card border border-border p-6">
          <PageHeader
            title="فروش و صندوق"
            headingLevel="h2"
            actions={
              <>
                <Button variant="outline">خروجی</Button>
                <Button>فروش جدید</Button>
              </>
            }
            className="mb-0"
          />
        </div>
      </Row>

      <Row label="کامل">
        <div className="w-full rounded-card border border-border p-6">
          <PageHeader
            eyebrow="گردش حساب"
            title="بانک ملت — جاری اصلی"
            headingLevel="h2"
            description="هر ورود و خروج پول این حساب، از قدیمی‌ترین به تازه‌ترین."
            back={{ href: '#page-header-demo', label: 'خزانه‌داری' }}
            meta={
              <>
                <StatusBadge status="active" />
                <span className="text-sm text-muted-foreground">
                  مانده: <Money rial={128_400_000} withUnit />
                </span>
              </>
            }
            actions={<Button variant="outline">مغایرت‌گیری</Button>}
            className="mb-0"
          />
        </div>
      </Row>

      <Row label="قرارداد">
        <p className="max-w-xl text-2xs text-muted-foreground">
          <code className="ltr-value">AppShell</code> یا <code className="ltr-value">title</code>{' '}
          می‌گیرد یا <code className="ltr-value">header</code> — نه هر دو. این یک union است، پس دادن
          همزمانشان خطای کامپایل می‌دهد، نه یادداشت در بازبینی کد.
        </p>
      </Row>
    </Section>
  );
}

/**
 * FilterBar — the search-and-chips row twelve list pages each wrote for themselves.
 *
 * Driven by local state here rather than by Inertia, so the gallery can show the filtered
 * and unfiltered states without a database behind it — the same reason the pickers take a
 * `search` function rather than a URL.
 */
function FilterBarSection({ alt }: { alt?: boolean }) {
  const [filters, setFilters] = useState<{ q: string; status: string | null }>({
    q: '',
    status: null,
  });

  const rows = filters.status === null ? 42 : filters.status === 'final' ? 31 : 11;

  return (
    <Section
      alt={alt}
      title="FilterBar"
      note="جستجوی تأخیردار، چیپ‌های وضعیت، پاک‌کردن و شمارش نتیجه — یک‌جا. زیر md فیلترها به یک شیت می‌روند."
    >
      <Row label="پیش‌فرض">
        <div className="w-full">
          <FilterBar
            search={{
              value: filters.q,
              label: 'جستجوی فاکتور',
              placeholder: 'شماره فاکتور، نام مشتری یا IMEI…',
            }}
            groups={[
              {
                key: 'status',
                label: 'وضعیت فاکتور',
                value: filters.status,
                options: [
                  { value: 'draft', label: 'پیش‌نویس' },
                  { value: 'final', label: 'نهایی' },
                  { value: 'void', label: 'ابطال‌شده' },
                ],
              },
            ]}
            onChange={(changes) =>
              setFilters((current) => ({
                q: changes.q !== undefined ? (changes.q ?? '') : current.q,
                status: changes.status !== undefined ? changes.status : current.status,
              }))
            }
            resultCount={rows}
            resultUnit="فاکتور"
          />
        </div>
      </Row>

      <Row label="فقط جستجو">
        <div className="w-full">
          <FilterBar
            search={{ value: '', label: 'جستجوی کالا', placeholder: 'نام یا بارکد کالا…' }}
            onChange={() => {}}
          />
        </div>
      </Row>

      <Row label="نکته‌ها">
        <ul className="max-w-xl list-disc space-y-1 pe-4 text-2xs text-muted-foreground">
          <li>چیپ‌ها ۴۰ پیکسل‌اند، نه ۲۸. فیلتر وضعیت اغلب تنها راه باریک‌کردن فهرست است.</li>
          <li>
            هر چیپ <code className="ltr-value">aria-pressed</code> دارد؛ رنگ به‌تنهایی وضعیت را
            نمی‌گوید.
          </li>
          <li>
            شمارش نتیجه <code className="ltr-value">aria-live=&quot;polite&quot;</code> است — تغییر
            فهرست باید شنیده شود، نه فقط دیده.
          </li>
          <li>
            <code className="ltr-value">withoutEmpty()</code> را در visit صفحه استفاده کنید، وگرنه
            پاک‌کردن فیلتر آدرس را به <code className="ltr-value">?q=&amp;status=</code> تبدیل
            می‌کند.
          </li>
        </ul>
      </Row>
    </Section>
  );
}

function ButtonSection({ alt }: { alt?: boolean }) {
  return (
    <Section alt={alt} title="Button" note="در هر صفحه فقط یک دکمه اصلی (brand) وجود دارد.">
      <Row label="variant">
        <Button>ثبت فاکتور</Button>
        <Button variant="secondary">ذخیره پیش‌نویس</Button>
        <Button variant="outline">انصراف</Button>
        <Button variant="ghost">بیشتر</Button>
        <Button variant="destructive">ابطال</Button>
        <Button variant="link">راهنما</Button>
      </Row>
      <Row label="size">
        <Button size="sm">کوچک</Button>
        <Button>معمولی</Button>
        <Button size="lg">بزرگ</Button>
        <Button size="icon" aria-label="افزودن">
          <PlusIcon className="size-4" />
        </Button>
      </Row>
      <Row label="با آیکون">
        <Button>
          <PlusIcon className="size-4" />
          افزودن گوشی
        </Button>
        <Button variant="outline">
          <SearchIcon className="size-4" />
          جستجو
        </Button>
      </Row>
      <Row label="disabled">
        <Button disabled>ثبت فاکتور</Button>
        <Button variant="outline" disabled>
          انصراف
        </Button>
      </Row>
      <Row label="loading">
        <Button disabled>
          <span className="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
          در حال ثبت…
        </Button>
      </Row>
    </Section>
  );
}

function FormSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="فرم‌ها"
      note="لیبل بالای فیلد، خطا زیر فیلد با متن قابل‌اقدام. مقادیر ذاتاً LTR (IMEI/موبایل/مبلغ لاتین) با dir=ltr داخلی."
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="g-name">نام مشتری</Label>
          <Input id="g-name" placeholder="مثلاً رضا محمدی" />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-imei">IMEI</Label>
          <Input
            id="g-imei"
            dir="ltr"
            inputMode="numeric"
            placeholder="356938035643809"
            className="ltr-value tabular"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-error">مبلغ (تومان)</Label>
          <Input
            id="g-error"
            dir="ltr"
            aria-invalid
            defaultValue="12,500"
            className="ltr-value tabular border-destructive"
          />
          <p className="text-2xs text-destructive">مبلغ نمی‌تواند از مانده فاکتور بیشتر باشد.</p>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-select">سطح قیمت</Label>
          <Select>
            <SelectTrigger id="g-select">
              <SelectValue placeholder="انتخاب کنید" />
            </SelectTrigger>
            {/* Portal: dir must be passed explicitly (design-system rule 2). */}
            <SelectContent dir="rtl">
              <SelectItem value="consumer">مصرف‌کننده</SelectItem>
              <SelectItem value="reseller">همکار</SelectItem>
              <SelectItem value="vip">همکار ویژه</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="g-note">توضیحات</Label>
          <Textarea id="g-note" rows={3} placeholder="ایراد اظهاری مشتری…" />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-disabled">غیرفعال</Label>
          <Input id="g-disabled" disabled defaultValue="قابل ویرایش نیست" />
        </div>
      </div>
    </Section>
  );
}

function OverlaySection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt
      title="لایه‌های شناور"
      note="همه Portalها dir=rtl صریح می‌گیرند، وگرنه انیمیشن و ترازشان برعکس می‌شود."
    >
      <Row label="Dialog">
        <Dialog>
          <DialogTrigger asChild>
            <Button variant="outline">ابطال فاکتور</Button>
          </DialogTrigger>
          <DialogContent dir="rtl">
            <DialogHeader>
              <DialogTitle>ابطال فاکتور ۱۴۰۵-۰۰۱۲؟</DialogTitle>
              <DialogDescription>
                موجودی گوشی‌ها به انبار برمی‌گردد و اسناد مالی معکوس می‌شود. این کار قابل بازگشت
                نیست.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline">انصراف</Button>
              <Button variant="destructive">ابطال کن</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </Row>

      <Row label="Sheet">
        <Sheet>
          <SheetTrigger asChild>
            <Button variant="outline">جزئیات دستگاه</Button>
          </SheetTrigger>
          <SheetContent side="right" dir="rtl">
            <SheetHeader>
              <SheetTitle>شناسنامه IMEI</SheetTitle>
            </SheetHeader>
            <div className="px-4 text-xs text-muted-foreground">
              <Num value="356938035643809" variant="ltr" />
            </div>
          </SheetContent>
        </Sheet>
      </Row>

      <Row label="DropdownMenu">
        {/* Radix takes `dir` on the Root for menu-style primitives (it drives keyboard
            navigation as well as placement), and on the Content for plain popovers. */}
        <DropdownMenu dir="rtl">
          <DropdownMenuTrigger asChild>
            <Button variant="outline">عملیات</Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem>ویرایش</DropdownMenuItem>
            <DropdownMenuItem>چاپ فاکتور</DropdownMenuItem>
            <DropdownMenuItem variant="destructive">ابطال</DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </Row>

      <Row label="Popover / Tooltip">
        <Popover>
          <PopoverTrigger asChild>
            <Button variant="outline">راهنما</Button>
          </PopoverTrigger>
          <PopoverContent dir="rtl" className="text-xs">
            سود این فاکتور بر پایه بهای خرید همان واحد سریالی محاسبه می‌شود.
          </PopoverContent>
        </Popover>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button variant="ghost" size="icon" aria-label="اطلاعات">
              <SmartphoneIcon className="size-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent dir="rtl">دستگاه فروخته‌شده — قابل ویرایش نیست</TooltipContent>
        </Tooltip>
      </Row>

      <Row label="Toast">
        <Button variant="outline" onClick={() => toast.success('فاکتور با موفقیت ثبت شد.')}>
          موفق
        </Button>
        <Button variant="outline" onClick={() => toast.error('اتصال به درگاه پیامک برقرار نشد.')}>
          خطا
        </Button>
        <Button variant="outline" onClick={() => toast.warning('اعتبار پیامک رو به اتمام است.')}>
          هشدار
        </Button>
      </Row>

      <Row label="Tabs">
        <Tabs defaultValue="items" className="w-full max-w-md">
          <TabsList>
            <TabsTrigger value="items">اقلام</TabsTrigger>
            <TabsTrigger value="payments">پرداخت‌ها</TabsTrigger>
            <TabsTrigger value="history">تاریخچه</TabsTrigger>
          </TabsList>
          <TabsContent value="items" className="pt-3 text-xs text-muted-foreground">
            اقلام فاکتور
          </TabsContent>
          <TabsContent value="payments" className="pt-3 text-xs text-muted-foreground">
            پرداخت‌های ترکیبی
          </TabsContent>
          <TabsContent value="history" className="pt-3 text-xs text-muted-foreground">
            تغییرات
          </TabsContent>
        </Tabs>
      </Row>
    </Section>
  );
}

function TableSection({ alt }: { alt?: boolean }) {
  const rows = [
    { imei: '356938035643809', model: 'iPhone 13 128GB', status: 'in_stock', cost: 425_000_000 },
    { imei: '351756051523999', model: 'Galaxy A54 256GB', status: 'reserved', cost: 118_000_000 },
    { imei: '013994005301234', model: 'Xiaomi Redmi 13C', status: 'sold', cost: 62_500_000 },
  ];

  return (
    <Section
      alt={alt}
      title="Table"
      note="اعداد مالی همیشه tabular و راست‌چین؛ IMEI همیشه LTR. سرستون مبلغ هم‌راستای مقادیرش."
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>IMEI</TableHead>
            <TableHead>مدل</TableHead>
            <TableHead>وضعیت</TableHead>
            <TableHead className="text-end">بهای خرید</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.imei}>
              <TableCell>
                <Num value={row.imei} variant="ltr" />
              </TableCell>
              <TableCell>{row.model}</TableCell>
              <TableCell>
                <StatusBadge status={row.status} />
              </TableCell>
              <TableCell className="text-end">
                <Money rial={row.cost} digits="latin" />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Section>
  );
}

function StateSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="حالت‌های صفحه"
      note="هر لیست باید حالت خالی و حالت بارگذاری داشته باشد."
    >
      <Row label="Skeleton">
        <div className="w-full max-w-md space-y-2">
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      </Row>

      <Row label="HistoryLink">
        {/*
          The door from a record into its own audit history. Ghost, not outline: it
          sits beside a page's real actions and must not compete with the one
          brand-filled button per view (design-system rule 7).
        */}
        <HistoryLink subject="product" record={1} />
        <HistoryLink subject="party" record={1} label="تاریخچه تغییرات" />
      </Row>

      <Row label="Badge">
        <Badge>پیش‌فرض</Badge>
        <Badge variant="secondary">ثانویه</Badge>
        <Badge variant="outline">خطی</Badge>
        <Badge variant="destructive">خطر</Badge>
      </Row>

      <div className="grid gap-4 lg:grid-cols-2">
        <EmptyState
          icon={WrenchIcon}
          title="تعمیری در جریان نیست"
          description="با ثبت اولین قبض پذیرش، کارتابل تعمیرات همین‌جا نمایش داده می‌شود."
          action={<Button size="sm">ثبت قبض پذیرش</Button>}
        />

        <EmptyState
          variant="search"
          icon={SearchIcon}
          title="نتیجه‌ای برای این فیلتر نیست"
          description="بازه تاریخ را بازتر کنید یا وضعیت را روی «همه» بگذارید."
          action={
            <Button size="sm" variant="outline">
              پاک کردن فیلترها
            </Button>
          }
        />
      </div>
    </Section>
  );
}

/* -------------------------------------------------------------------------- */

/**
 * StatCard — one number with just enough context.
 *
 * The row below is the review case that matters: `invertTrend` on the last card. A
 * rising overdue balance rendering green is worse than showing no trend at all.
 */
/**
 * The quota surfaces, reviewed together because they are read together: a shopkeeper who
 * sees the block has been watching the meter go amber for a week.
 *
 * The state matrix is the point of having them here. The one that is easiest to get wrong
 * is `reached` versus `blocked` — a credit that is exactly full has stopped nobody, and
 * turning it red would tell a shop that spent precisely what it bought that something is
 * wrong.
 */
function FormErrorsSection({ alt = false }: { alt?: boolean }) {
  return (
    <Section
      title="FormErrors"
      note="خانهٔ خطاهایی که به هیچ فیلدی تعلق ندارند. بدون آن، خطای «lines» یا «accessories» جایی برای نمایش ندارد و دکمهٔ ثبت بی‌صدا کاری نمی‌کند — و فروشنده، با مشتری جلوی پیشخوان، دوباره فشار می‌دهد و نتیجه می‌گیرد که نرم‌افزار خراب است."
      alt={alt}
    >
      <Row label="یک خطا">
        <FormErrors errors={{ lines: 'حداقل یک قلم کالا لازم است.' }} className="max-w-xl" />
      </Row>

      {/* A different screen state from `empty`, and conflating the two is how a shop
          concludes their data has gone missing. Muted rather than red: being outside a
          permission is an ordinary fact about a role, not a failure. */}
      <Row label="بدون دسترسی">
        <EmptyState
          variant="permission"
          title="گزارشی در دسترس شما نیست"
          description="دسترسی «مشاهده گزارش‌ها» به حساب شما داده نشده است. مدیر فروشگاه می‌تواند آن را از بخش نقش‌ها فعال کند."
        />
      </Row>

      <Row label="چند خطا">
        <FormErrors
          errors={{
            lines: 'حداقل یک قلم کالا لازم است.',
            payments: 'جمع پرداخت‌ها با مبلغ فاکتور برابر نیست.',
            branch_id: 'شعبه انتخاب نشده است.',
          }}
          className="max-w-xl"
        />
      </Row>

      <Row label="خطای تودرتو — به والدش خلاصه می‌شود">
        {/* The form renders `errors.lines` beside its table, so `lines.2.quantity` must
            not appear here as well: one problem shown twice reads as two problems. */}
        <FormErrors
          errors={{
            'lines.2.quantity': 'تعداد باید بیشتر از صفر باشد.',
            imei: 'کد IMEI نامعتبر است.',
          }}
          handled={['lines']}
          className="max-w-xl"
        />
      </Row>

      <Row label="همه جای دیگری نمایش داده شده‌اند — چیزی رندر نمی‌شود">
        <div className="text-sm text-muted-foreground">
          <FormErrors errors={{ name: 'نام لازم است.' }} handled={['name']} />
          (خالی — این خطا کنار فیلد خودش نمایش داده می‌شود)
        </div>
      </Row>

      <Row label="سهمیه — هرگز اینجا نه">
        <div className="text-sm text-muted-foreground">
          {/* `quota` is rendered once in the shell by <QuotaBlock>, with a price and an
              upgrade button. Repeating it here as a bare sentence would put a worse
              version of the same message above a better one. */}
          <FormErrors errors={{ quota: 'سهمیهٔ ۳۰۰ فاکتور این ماه تمام شد.' }} />
          (خالی — «سهمیه» را QuotaBlock در پوستهٔ صفحه نشان می‌دهد)
        </div>
      </Row>
    </Section>
  );
}

function QuotaSection({ alt = false }: { alt?: boolean }) {
  const meter = (over: Partial<UsageMeterState> = {}): UsageMeterState => ({
    key: 'sales.invoices',
    label: 'فاکتور فروش',
    unit: 'فاکتور',
    module: 'sales',
    used: 42,
    limit: 300,
    window: 'month',
    resets_at: '2026-09-22T20:30:00Z',
    level: 'ok',
    ...over,
  });

  return (
    <Section
      title="UsageMeter · UsageBanner · QuotaBlock"
      note="سهمیهٔ ماهانه: نوار مصرف، نوار هشدار بالای صفحه، و صفحهٔ «سهمیه تمام شد» با دکمهٔ ارتقا. رنگ قرمز فقط وقتی است که سهمیه واقعاً جلوی کاری را گرفته باشد؛ سهمیهٔ پرشده اما بی‌مانع، کهربایی است."
      alt={alt}
    >
      <Row label="عادی">
        <UsageMeter meter={meter()} className="max-w-sm" />
      </Row>

      <Row label="نزدیک سقف">
        <UsageMeter meter={meter({ used: 258, level: 'warning' })} className="max-w-sm" />
      </Row>

      <Row label="پر، ولی کسی را متوقف نکرده">
        <UsageMeter meter={meter({ used: 300, level: 'reached' })} className="max-w-sm" />
      </Row>

      <Row label="متوقف‌کننده">
        <UsageMeter meter={meter({ used: 300, level: 'blocked' })} className="max-w-sm" />
      </Row>

      <Row label="نامحدود">
        <UsageMeter meter={meter({ limit: null, used: 1840 })} className="max-w-sm" />
      </Row>

      <Row label="ظرفیت کل (بدون تازه‌شدن)">
        <UsageMeter
          meter={meter({
            key: 'identity.users',
            label: 'کاربر فعال',
            unit: 'کاربر',
            window: 'total',
            used: 2,
            limit: 2,
            level: 'reached',
            resets_at: null,
          })}
          className="max-w-sm"
        />
      </Row>

      <Row label="نوار هشدار">
        <UsageBanner
          usage={{
            plan: { code: 'basic', name: 'پایه', lapsed: false },
            meters: [meter({ used: 258, level: 'warning' })],
            attention: ['sales.invoices'],
          }}
        />
      </Row>

      <Row label="نوار — اشتراک تمام‌شده">
        <UsageBanner
          usage={{
            plan: { code: 'basic', name: 'پایه', lapsed: true },
            meters: [],
            attention: [],
          }}
        />
      </Row>

      <Row label="سهمیه تمام شد — با اجازهٔ خرید">
        <QuotaBlock
          block={{
            metric: 'sales.invoices',
            label: 'فاکتور فروش',
            message:
              'سهمیهٔ ۳۰۰ فاکتور این ماه در پلن پایه تمام شد. پلن حرفه‌ای ماهی ۵٬۰۰۰ فاکتور دارد. سهمیهٔ پلن فعلی ۱ مهر تازه می‌شود.',
            used: 300,
            limit: 300,
            requested: 1,
            resets_at: '2026-09-22T20:30:00Z',
            next_plan: {
              code: 'pro',
              name: 'حرفه‌ای',
              limit: 5000,
              price: { value: 5_900_000, formatted: '۵۹۰٬۰۰۰ تومان' },
              due: { value: 2_400_000, formatted: '۲۴۰٬۰۰۰ تومان' },
            },
            can_upgrade: true,
          }}
        />
      </Row>

      <Row label="سهمیه تمام شد — بالاترین پلن، جایی برای ارتقا نیست">
        <QuotaBlock
          block={{
            metric: 'identity.users',
            label: 'کاربر فعال',
            // A standing capacity on the top rung: no month to wait for and no plan to
            // move to, so the only sentence worth printing is the one action that works.
            message:
              'ظرفیت ۲۵ کاربر پلن نامحدود تکمیل است. با آزاد کردن یکی از کاربر‌های موجود هم جا باز می‌شود.',
            used: 25,
            limit: 25,
            requested: 1,
            resets_at: null,
            next_plan: null,
            can_upgrade: true,
          }}
        />
      </Row>

      <Row label="سهمیه تمام شد — بدون اجازهٔ خرید">
        <QuotaBlock
          block={{
            metric: 'repairs.tickets',
            label: 'قبض پذیرش تعمیر',
            message: 'سهمیهٔ ۱۰۰ قبض پذیرش تعمیر این ماه در پلن پایه تمام شد.',
            used: 100,
            limit: 100,
            requested: 1,
            resets_at: '2026-09-22T20:30:00Z',
            next_plan: {
              code: 'pro',
              name: 'حرفه‌ای',
              limit: 1500,
              price: { value: 5_900_000, formatted: '۵۹۰٬۰۰۰ تومان' },
              due: { value: 5_900_000, formatted: '۵۹۰٬۰۰۰ تومان' },
            },
            can_upgrade: false,
          }}
        />
      </Row>

      <Row label="ورود گروهی — بیش از سهمیه">
        <QuotaBlock
          block={{
            metric: 'catalog.products',
            label: 'کالای جدید',
            message:
              'این عملیات ۴۰ کالا می‌خواهد و سهمیهٔ باقی‌ماندهٔ شما ۱۲ است. پلن حرفه‌ای ماهی ۲٬۰۰۰ کالا دارد.',
            used: 188,
            limit: 200,
            requested: 40,
            resets_at: '2026-09-22T20:30:00Z',
            next_plan: {
              code: 'pro',
              name: 'حرفه‌ای',
              limit: 2000,
              price: { value: 5_900_000, formatted: '۵۹۰٬۰۰۰ تومان' },
              due: { value: 2_400_000, formatted: '۲۴۰٬۰۰۰ تومان' },
            },
            can_upgrade: true,
          }}
        />
      </Row>

      <Row label="بالاترین پلن — جایی برای ارتقا نیست">
        <QuotaBlock
          block={{
            metric: 'identity.users',
            label: 'کاربر فعال',
            message: 'ظرفیت ۲۵ کاربر این پلن پر شده است.',
            used: 25,
            limit: 25,
            requested: 1,
            resets_at: null,
            next_plan: null,
            can_upgrade: true,
          }}
        />
      </Row>
    </Section>
  );
}

function StatCardSection({ alt = false }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="StatCard"
      note="عدد + زمینه. روند صعودی همیشه خوب نیست — کارت آخر با invertTrend."
    >
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="فروش امروز" value={128_500_000} isMoney trend={12} icon={TrendingUpIcon} />
        <StatCard label="دستگاه موجود" value={47} hint="در ۲ انبار" icon={SmartphoneIcon} />
        <StatCard
          label="در انتظار قطعه"
          value={6}
          tone="warning"
          icon={WrenchIcon}
          hint="بیش از ۳ روز"
        />
        <StatCard
          label="مطالبات معوق"
          value={94_000_000}
          isMoney
          trend={8}
          invertTrend
          tone="danger"
          hint="۵ طرف حساب"
        />
      </div>

      <p className="mt-6 text-sm text-muted-foreground">بدون روند و بدون توضیح:</p>
      <div className="mt-3 grid gap-4 sm:grid-cols-2">
        <StatCard label="تعداد کاربران" value={3} />
        <StatCard label="اعتبار پیامک" value={0} hint="نیاز به شارژ" tone="warning" />
      </div>

      <p className="mt-6 text-sm text-muted-foreground">
        صفر در برابر «تعیین‌نشده» — دو چیز متفاوت‌اند و نباید یک‌شکل دیده شوند:
      </p>
      <div className="mt-3 grid gap-4 sm:grid-cols-2">
        <StatCard label="سقف اعتبار" value={0} isMoney hint="سقف صفر: اعتباری ندارد" />
        <StatCard label="سقف اعتبار" value={null} hint="تعیین نشده" />
      </div>
    </Section>
  );
}

/**
 * ShareBar — a slice against its whole.
 *
 * The states that matter are the degenerate ones. A whole of zero must render nothing
 * rather than divide by zero; a negative slice (an overdrawn account) must clamp to an
 * empty track rather than grow leftwards; and a slice too small to see must still not
 * be labelled «۰٪» beside a visible sliver, which is why `sharePercent` floors at 1.
 */
function ShareBarSection({ alt = false }: { alt?: boolean }) {
  const total = 712_490_000_0;

  const slices: { label: string; value: number }[] = [
    { label: 'بانک', value: 482_000_000_0 },
    { label: 'صندوق', value: 71_840_000_0 },
    { label: 'کارتخوان', value: 33_650_000_0 },
    { label: 'سهم بسیار کوچک', value: 120_000 },
  ];

  return (
    <Section
      alt={alt}
      title="ShareBar"
      note="نوار سهم: بزرگی را بدون خواندن عدد نشان می‌دهد. درصد همیشه کنارش نوشته می‌شود، پس خود نوار برای صفحه‌خوان پنهان است."
    >
      <div className="max-w-md space-y-4">
        {slices.map((slice) => (
          <div key={slice.label}>
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span>{slice.label}</span>
              <Money rial={slice.value} />
            </div>
            <ShareBar value={slice.value} total={total} className="mt-2" />
            <p className="mt-1.5 text-2xs text-muted-foreground">
              <Num value={sharePercent(slice.value, total)} />٪ از کل
            </p>
          </div>
        ))}
      </div>

      <Row label="تُن‌ها">
        <div className="w-full max-w-md space-y-3">
          <ShareBar value={70} total={100} tone="brand" />
          <ShareBar value={70} total={100} tone="success" />
          <ShareBar value={70} total={100} tone="warning" />
          <ShareBar value={70} total={100} tone="danger" />
          <ShareBar value={70} total={100} tone="neutral" />
        </div>
      </Row>

      <Row label="حالت‌های مرزی">
        <div className="w-full max-w-md space-y-3">
          <div>
            <p className="mb-1.5 text-2xs text-muted-foreground">پر (۱۰۰٪)</p>
            <ShareBar value={100} total={100} />
          </div>
          <div>
            <p className="mb-1.5 text-2xs text-muted-foreground">صفر — ریل خالی، نه نوار غایب</p>
            <ShareBar value={0} total={100} />
          </div>
          <div>
            <p className="mb-1.5 text-2xs text-muted-foreground">
              منفی — به خالی محدود می‌شود، به چپ رشد نمی‌کند
            </p>
            <ShareBar value={-40} total={100} tone="danger" />
          </div>
          <div>
            <p className="mb-1.5 text-2xs text-muted-foreground">
              کلِ صفر — هیچ چیز رندر نمی‌شود (زیر این خط چیزی نیست)
            </p>
            <ShareBar value={40} total={0} />
          </div>
        </div>
      </Row>
    </Section>
  );
}

/**
 * BarChart — the only chart in the system so far.
 *
 * States to review: a normal month, a shop that sold nothing, a single day, and the
 * quiet-day case — a day with one small sale must not look like a day the shop was
 * shut. Hover a column and read the fixed line above the plot; on a phone, tap.
 */
function BarChartSection({ alt = false }: { alt?: boolean }) {
  const busy = Array.from({ length: 30 }, (_, index) => ({
    // Through the digit helper rather than padding with a Persian zero: `'1'.padStart(2,
    // '۰')` yields «۰1», one Persian digit glued to one Latin one, which is exactly the
    // mixed-digit bug the gallery exists to demonstrate the absence of.
    label: `۱۴۰۵/۰۵/${toPersianDigits(String(index + 1).padStart(2, '0'))}`,
    // A deterministic-looking month with two closed days and one big Thursday.
    value:
      index === 6 || index === 13 ? 0 : (index % 7 === 3 ? 90 : 12 + (index % 5) * 7) * 1_000_000,
  }));

  const quiet = busy.map((point, index) => ({
    ...point,
    value: index === 20 ? 480_000_000 : index % 3 === 0 ? 0 : 900_000,
  }));

  return (
    <Section
      alt={alt}
      title="BarChart"
      note="یک سری، یک رنگ. زمان از راست به چپ می‌رود. روزهای بدون فروش یک پایه کم‌رنگ دارند تا ستون قابل اشاره بماند."
    >
      <div className="grid gap-8 lg:grid-cols-2">
        <div className="rounded-card border bg-card p-5">
          <BarChart points={busy} title="فروش ۳۰ روز گذشته" />
        </div>

        <div className="rounded-card border bg-card p-5">
          <BarChart points={busy.map((p) => ({ ...p, value: 0 }))} title="فروش ۳۰ روز گذشته" />
        </div>

        <div className="rounded-card border bg-card p-5">
          <BarChart points={quiet} title="یک روز بزرگ و بقیه ساکت" />
        </div>

        <div className="rounded-card border bg-card p-5">
          <BarChart points={busy.slice(0, 1)} title="فقط یک روز" />
        </div>
      </div>
    </Section>
  );
}

/**
 * IMEIInput — the field the product turns on.
 *
 * States to review: empty, partial, valid, invalid checksum, server error, disabled.
 * Note the LTR digits inside an RTL form, and that Persian digits are accepted.
 */
function ImeiSection({ alt = false }: { alt?: boolean }) {
  const [partial, setPartial] = useState('35693803');
  const [valid, setValid] = useState('356938035643809');
  const [invalid, setInvalid] = useState('356938035643801');
  const [persian, setPersian] = useState('۳۵۶۹۳۸۰۳۵۶۴۳۸۰۹');

  return (
    <Section
      alt={alt}
      title="IMEIInput"
      note="ارقام LTR داخل فرم RTL. ارقام فارسی پذیرفته و به لاتین تبدیل می‌شوند."
    >
      <div className="grid gap-6 md:grid-cols-2">
        <ImeiInput value={partial} onChange={setPartial} label="در حال تایپ" />
        <ImeiInput value={valid} onChange={setValid} label="معتبر" />
        <ImeiInput value={invalid} onChange={setInvalid} label="رقم کنترلی نامعتبر" />
        <ImeiInput value={persian} onChange={setPersian} label="ورودی با ارقام فارسی" />
        <ImeiInput value="" onChange={() => undefined} label="IMEI دوم" optional />
        <ImeiInput
          value="356938035643809"
          onChange={() => undefined}
          label="خطای سرور"
          error="این IMEI قبلاً در فروشگاه شما ثبت شده است."
        />
        <ImeiInput value="356938035643809" onChange={() => undefined} label="غیرفعال" disabled />
      </div>
    </Section>
  );
}

/**
 * DataTable — the one table.
 *
 * Review at 390px: the secondary column disappears rather than the table cramping, and
 * the wrapper scrolls instead of the page.
 */
function DataTableSection({ alt = false }: { alt?: boolean }) {
  const [search, setSearch] = useState('');
  const [sortKey, setSortKey] = useState('name');

  const rows = [
    {
      id: 1,
      name: 'آیفون ۱۵ پرو ۲۵۶',
      imei: '356938035643809',
      price: 890_000_000,
      status: 'in_stock',
    },
    { id: 2, name: 'گلکسی A54', imei: '351234567890123', price: 128_000_000, status: 'reserved' },
    { id: 3, name: 'ردمی نوت ۱۳', imei: '352099001761481', price: 74_500_000, status: 'sold' },
  ];

  const columns = [
    { key: 'name', header: 'کالا', sortable: true, cell: (r: (typeof rows)[0]) => r.name },
    {
      key: 'imei',
      header: 'IMEI',
      secondary: true,
      cell: (r: (typeof rows)[0]) => <Num value={r.imei} variant="ltr" />,
    },
    {
      key: 'price',
      header: 'قیمت',
      numeric: true,
      sortable: true,
      cell: (r: (typeof rows)[0]) => <Money rial={r.price} digits="latin" />,
    },
    {
      key: 'status',
      header: 'وضعیت',
      cell: (r: (typeof rows)[0]) => <StatusBadge status={r.status} />,
    },
  ];

  return (
    <Section
      alt={alt}
      title="DataTable"
      note="در ۳۹۰ پیکسل ستون ثانویه حذف می‌شود؛ اسکرول افقی داخل جدول است نه صفحه."
    >
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        caption="نمونه فهرست کالا"
        search={{ value: search, onChange: setSearch, placeholder: 'نام یا IMEI…' }}
        sort={{ key: sortKey, direction: 'asc', onChange: setSortKey }}
      />

      <p className="mt-8 text-sm text-muted-foreground">در حال بارگذاری:</p>
      <div className="mt-3">
        <DataTable columns={columns} rows={[]} rowKey={(r) => r.id} caption="نمونه" loading />
      </div>

      <p className="mt-8 text-sm text-muted-foreground">خالی، و «جستجوی بی‌نتیجه»:</p>
      <div className="mt-3 space-y-4">
        <DataTable columns={columns} rows={[]} rowKey={(r) => r.id} caption="خالی" />
        <DataTable
          columns={columns}
          rows={[]}
          rowKey={(r) => r.id}
          caption="بی‌نتیجه"
          search={{ value: 'گوشی نایاب', onChange: () => undefined }}
        />
      </div>
    </Section>
  );
}

/**
 * PartyPicker & UnitPicker — the two lookups every document starts with.
 *
 * Both talk to a module endpoint in the product; here they are driven by fixtures, so
 * the states that are hardest to reach against a real database — still loading, no
 * results, request failed — can actually be reviewed.
 *
 * What to check: open each one (the panel only exists while open), then confirm the
 * IMEI reads left-to-right inside the right-to-left row, the balance says
 * بدهکار/بستانکار rather than carrying a minus sign, and the panel matches the trigger
 * width at 390px instead of overflowing it.
 */
function PickerSection({ alt = false }: { alt?: boolean }) {
  const PARTIES: PartyOption[] = [
    {
      id: 1,
      name: 'محمدرضا کریمی‌نژاد',
      company_name: 'موبایل کریمی',
      kind: 'colleague',
      kind_label: 'همکار',
      mobile: '09121112233',
      balance: { value: 128_500_000, formatted: '۱۲٬۸۵۰٬۰۰۰ تومان' },
    },
    {
      id: 2,
      name: 'سمیرا احمدی',
      company_name: null,
      kind: 'customer',
      kind_label: 'مشتری',
      mobile: '09351234567',
      balance: { value: 0, formatted: '۰ تومان' },
    },
    {
      id: 3,
      name: 'پخش قطعات جنوب شرق تهران',
      company_name: 'شرکت تجارت الکترونیک آریا',
      kind: 'supplier',
      kind_label: 'تأمین‌کننده',
      mobile: '02133445566',
      balance: { value: -46_200_000, formatted: '۴٬۶۲۰٬۰۰۰- تومان' },
    },
  ];

  const UNITS: UnitOption[] = [
    {
      id: 11,
      imei1: '356938035643809',
      imei2: '356938035643817',
      serial: null,
      product_name: 'آیفون ۱۵ پرو مکس',
      variant_name: 'تیتانیوم طبیعی · ۲۵۶ گیگ',
      status: 'in_stock',
      condition_label: 'نو',
      grade: null,
      warehouse_name: 'انبار فروشگاه مرکزی',
      cost: { value: 780_000_000, formatted: '۷۸٬۰۰۰٬۰۰۰ تومان' },
    },
    {
      id: 12,
      imei1: '352099001761481',
      imei2: null,
      serial: null,
      product_name: 'گلکسی S24 اولترا',
      variant_name: 'مشکی · ۵۱۲ گیگ',
      status: 'reserved',
      condition_label: 'دست‌دوم',
      grade: 'A',
      warehouse_name: 'انبار شعبه ونک',
      cost: { value: 412_000_000, formatted: '۴۱٬۲۰۰٬۰۰۰ تومان' },
    },
  ];

  const [party, setParty] = useState<PartyOption | null>(null);
  const [chosenParty, setChosenParty] = useState<PartyOption | null>(PARTIES[0] ?? null);
  const [unit, setUnit] = useState<UnitOption | null>(null);
  const [chosenUnit, setChosenUnit] = useState<UnitOption | null>(UNITS[0] ?? null);

  /** Resolves after a beat, the way a real request does. */
  const found = useCallback(
    <TRow,>(rows: TRow[]) =>
      () =>
        new Promise<TRow[]>((resolve) => window.setTimeout(() => resolve(rows), 220)),
    []
  );

  const parties = useMemo(() => found(PARTIES)(), [found]);
  const partySearch = useCallback(() => parties, [parties]);
  const unitsPromise = useMemo(() => found(UNITS)(), [found]);
  const unitSearch = useCallback(() => unitsPromise, [unitsPromise]);
  const noResults = useCallback(() => Promise.resolve([]), []);
  // Never settles — the only honest way to hold a component in its loading state.
  const pending = useCallback(() => new Promise<never[]>(() => undefined), []);
  const failing = useCallback(() => Promise.reject(new Error('gallery fixture')), []);

  return (
    <Section
      alt={alt}
      title="PartyPicker / UnitPicker"
      note="هر دو پیکر با داده نمونه کار می‌کنند. برای دیدن پنل، روی فیلد کلیک کنید. حالت‌های «در حال بارگذاری»، «بی‌نتیجه» و «خطا» هم اینجا ساخته شده‌اند."
    >
      <div className="grid gap-8 lg:grid-cols-2">
        <div className="space-y-6">
          <div className="space-y-2">
            <Label>انتخاب طرف حساب — خالی</Label>
            <PartyPicker value={party} onChange={setParty} search={partySearch} />
          </div>

          <div className="space-y-2">
            <Label>انتخاب‌شده، با مانده حساب</Label>
            <PartyPicker value={chosenParty} onChange={setChosenParty} search={partySearch} />
          </div>

          <div className="space-y-2">
            <Label>بدون دسترسی به مانده حساب</Label>
            <PartyPicker
              value={{ ...(PARTIES[1] as PartyOption), balance: null }}
              onChange={() => undefined}
              search={partySearch}
            />
          </div>

          <div className="space-y-2">
            <Label>خطای فرم</Label>
            <PartyPicker value={null} onChange={() => undefined} invalid search={partySearch} />
            <p className="text-sm text-danger">انتخاب طرف حساب برای فاکتور اعتباری الزامی است.</p>
          </div>

          <div className="space-y-2">
            <Label>غیرفعال</Label>
            <PartyPicker
              value={PARTIES[2] as PartyOption}
              onChange={() => undefined}
              disabled
              search={partySearch}
            />
          </div>
        </div>

        <div className="space-y-6">
          <div className="space-y-2">
            <Label>اسکن دستگاه — خالی</Label>
            <UnitPicker value={unit} onChange={setUnit} search={unitSearch} />
          </div>

          <div className="space-y-2">
            <Label>انتخاب‌شده</Label>
            <UnitPicker value={chosenUnit} onChange={setChosenUnit} search={unitSearch} />
          </div>

          <div className="space-y-2">
            <Label>خطای فرم</Label>
            <UnitPicker value={null} onChange={() => undefined} invalid search={unitSearch} />
            <p className="text-sm text-danger">این دستگاه پیش‌تر در فاکتور دیگری ثبت شده است.</p>
          </div>

          <div className="space-y-2">
            <Label>غیرفعال</Label>
            <UnitPicker
              value={UNITS[1] as UnitOption}
              onChange={() => undefined}
              disabled
              search={unitSearch}
            />
          </div>
        </div>
      </div>

      <div className="grid gap-8 lg:grid-cols-3">
        <div className="space-y-2">
          <Label>در حال بارگذاری</Label>
          <PartyPicker value={null} onChange={() => undefined} search={pending} />
        </div>

        <div className="space-y-2">
          <Label>بی‌نتیجه</Label>
          <UnitPicker value={null} onChange={() => undefined} search={noResults} />
        </div>

        <div className="space-y-2">
          <Label>خطای شبکه</Label>
          <PartyPicker value={null} onChange={() => undefined} search={failing} />
        </div>
      </div>
    </Section>
  );
}

/**
 * Pagination & ConfirmDialog — the two pieces every list screen needs.
 *
 * Pagination takes Laravel's `linkCollection()` verbatim. Check that the arrows point
 * the RTL way (previous is on the right), that the current page is the only filled
 * pill, and that a disabled arrow is visibly inert rather than merely unclickable.
 *
 * ConfirmDialog exists to enforce copy, not to look pretty: title names the thing,
 * description says what happens to it, button carries the verb.
 */
function ConfirmAndPagingSection({ alt = false }: { alt?: boolean }) {
  const links = (current: number, pages: number) => [
    { url: current > 1 ? '#prev' : null, label: 'قبلی', active: false },
    ...Array.from({ length: pages }, (_, index) => ({
      url: `#page-${index + 1}`,
      label: String(index + 1),
      active: index + 1 === current,
    })),
    { url: current < pages ? '#next' : null, label: 'بعدی', active: false },
  ];

  const [open, setOpen] = useState(false);
  const [openSafe, setOpenSafe] = useState(false);

  return (
    <Section
      alt={alt}
      title="Pagination / ConfirmDialog"
      note="فلش «قبلی» در چیدمان راست‌به‌چپ سمت راست است. در حالت اول و آخر، فلش غیرفعال دیده می‌شود نه اینکه فقط کار نکند."
    >
      <Row label="صفحه اول">
        <Pagination links={links(1, 5)} total={112} unit="کالا" className="w-full" />
      </Row>

      <Row label="صفحه میانی">
        <Pagination links={links(3, 5)} total={112} unit="کالا" className="w-full" />
      </Row>

      <Row label="یک صفحه (بدون کنترل)">
        <Pagination links={links(1, 1)} total={7} unit="کالا" className="w-full" />
      </Row>

      <Row label="تأیید حذف">
        <Button variant="destructive" onClick={() => setOpen(true)}>
          حذف کالا
        </Button>
        <Button variant="outline" onClick={() => setOpenSafe(true)}>
          اقدام غیرمخرب
        </Button>

        <ConfirmDialog
          open={open}
          onOpenChange={setOpen}
          title="حذف «آیفون ۱۵ پرو»"
          description="این کالا از فهرست و از فروش خارج می‌شود. فاکتورها، گردش انبار و دستگاه‌های ثبت‌شده حذف نمی‌شوند."
          confirmLabel="حذف کالا"
          onConfirm={() => setOpen(false)}
        />

        <ConfirmDialog
          open={openSafe}
          onOpenChange={setOpenSafe}
          destructive={false}
          title="ارسال دوباره دعوت‌نامه"
          description="یک پیامک تازه با لینک جدید برای همان شماره فرستاده می‌شود و لینک قبلی باطل می‌گردد."
          confirmLabel="ارسال دوباره"
          onConfirm={() => setOpenSafe(false)}
        />
      </Row>
    </Section>
  );
}

/**
 * PrintLayout — the three papers the system owns.
 *
 * Each sheet is shown at its real width, because that is the point: what is on screen
 * is what comes out of the printer. Only ONE of these may be on a real page —
 * `@page` is a document-level rule and cannot be scoped — so the gallery is the only
 * place all three appear together, and the size rule here is deliberately not applied.
 *
 * What to review: ink on white regardless of theme, RTL preserved on paper, and the
 * 80mm receipt legible at its true width rather than scaled down to fit.
 */
function ReportPresetsSection({ alt = false }: { alt?: boolean }) {
  const saved: ReportPreset[] = [
    { id: 1, name: 'سه ماه گذشته', filters: { cut: 'aging', direction: 'receivable' } },
    { id: 2, name: 'بدهی همکاران', filters: { cut: 'aging', direction: 'payable' } },
  ];

  return (
    <Section
      title="<ReportPresets/>"
      note="فیلترهای ذخیره‌شده هر گزارش، برای هر کاربر. کلیک روی یک نام، صفحه را با همان فیلترها باز می‌کند — یعنی نشانی صفحه هم همان می‌شود و قابل اشتراک است. حالت خالی عمداً فقط دکمهٔ ذخیره است: ردیف خالی چیزی برای گفتن ندارد."
      alt={alt}
    >
      <div className="space-y-6">
        <Row label="با چند فیلتر ذخیره‌شده">
          <ReportPresets
            reportKey="financial"
            presets={saved}
            current={{ cut: 'aging' }}
            path="/reporting/financial"
          />
        </Row>

        <Row label="خالی (اولین بار)">
          <ReportPresets
            reportKey="financial"
            presets={[]}
            current={{ cut: 'aging' }}
            path="/reporting/financial"
          />
        </Row>
      </div>
    </Section>
  );
}

/* -------------------------------------------------------------------------- */

function PrintSection({ alt = false }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="PrintLayout"
      note="کاغذ همیشه سفید است، حتی وقتی برنامه در حالت تیره باز شده باشد. عرض‌ها واقعی‌اند: ۸۰ میلی‌متر، A5 و A4."
    >
      {/*
        Paper does not reflow. A sheet is 80mm, 148mm or 210mm wide because that is what
        comes out of the printer, so on a 375px phone the A4 specimen is simply wider than
        the viewport and no amount of `max-w-full` changes that without lying about the
        size being demonstrated.

        So the sheets scroll inside their own lane rather than pushing the page sideways —
        the system's standing rule for content that cannot shrink. `min-w-0` on the tracks
        is what makes it work: a grid track is `minmax(auto, max-content)` by default, and
        without it the track floors at the label row's content width and the overflow moves
        up to the document instead of being caught here.
      */}
      <div className="grid gap-8 lg:grid-cols-[auto_1fr] lg:items-start">
        <div className="w-[80mm] min-w-0 max-w-full overflow-x-auto border border-border bg-white p-4 text-black">
          <p className="text-center text-sm font-bold">فروشگاه موبایل نمونه</p>
          <p className="mt-1 text-center text-2xs">۸۰ میلی‌متر — رسید حرارتی</p>
          <div className="my-3 border-t border-dashed border-black/30" />
          <div className="space-y-1 text-2xs">
            <div className="flex justify-between">
              <span>آیفون ۱۵ پرو ۲۵۶</span>
              <span className="tabular">۸۹٬۰۰۰٬۰۰۰</span>
            </div>
            <div className="flex justify-between">
              <span>قاب محافظ</span>
              <span className="tabular">۴۵۰٬۰۰۰</span>
            </div>
          </div>
          <div className="my-3 border-t border-dashed border-black/30" />
          <div className="flex justify-between text-xs font-bold">
            <span>جمع کل</span>
            <span className="tabular">۸۹٬۴۵۰٬۰۰۰ تومان</span>
          </div>
        </div>

        <div className="min-w-0 space-y-4 overflow-x-auto">
          <div className="w-[148mm] max-w-full border border-border bg-white p-6 text-black">
            <p className="text-sm font-bold">A5 — فاکتور نصف‌برگی</p>
            <p className="mt-1 text-2xs text-black/60">
              همان فاکتوری که بیشتر مغازه‌ها واقعاً دست مشتری می‌دهند.
            </p>
          </div>

          <div className="w-full border border-border bg-white p-6 text-black">
            <p className="text-sm font-bold">A4 — فاکتور رسمی و برگه برچسب</p>
            <p className="mt-1 text-2xs text-black/60">
              برگه برچسب هم روی همین کاغذ چاپ می‌شود؛ اندازه هر برچسب به میلی‌متر تعیین می‌شود.
            </p>
            <div className="mt-4 flex flex-wrap gap-[2mm]">
              {Array.from({ length: 5 }).map((_, index) => (
                <div
                  key={index}
                  className="flex h-[25mm] w-[38mm] flex-col justify-between border border-black/15 p-[1.5mm]"
                >
                  <p className="text-[6pt] leading-tight">قاب محافظ شفاف</p>
                  <div className="h-[8mm] bg-[repeating-linear-gradient(90deg,#000_0_1px,transparent_1px_3px)]" />
                  <div className="flex items-end justify-between">
                    <span className="ltr-value text-[5pt] tabular" dir="ltr">
                      6260000000019
                    </span>
                    <span className="rounded-[1mm] bg-label px-[1mm] text-[8pt] font-bold tabular">
                      ۴۵۰٬۰۰۰
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </Section>
  );
}

/**
 * Timeline — the 360° customer history.
 *
 * Assembled from every module, so the review case that matters is a *mixed* list: the
 * icons and tones have to stay distinguishable when six kinds of event sit next to each
 * other, and the money lines have to read correctly in both directions without a bare
 * minus sign.
 *
 * Also check the failure notice: a module that could not answer is named, because a
 * customer page quietly missing its repair history is how somebody concludes a device
 * was never brought in.
 */
function TimelineSection({ alt = false }: { alt?: boolean }) {
  const items: TimelineItem[] = [
    {
      occurred_at: '2026-08-09T08:15:00Z',
      kind: 'payment',
      title: 'پرداخت / بستانکار شد',
      description: 'نقدی بابت فاکتور ۱۴۰۵-۰۰۱۲',
      amount: -45_000_000,
      url: null,
      actor: 'سمیرا احمدی',
    },
    {
      occurred_at: '2026-08-08T11:40:00Z',
      kind: 'device',
      title: 'دستگاه از این طرف حساب خریداری شد',
      description: 'آیفون ۱۵ پرو مکس · 356938035643809',
      amount: null,
      url: '#unit',
      actor: null,
    },
    {
      occurred_at: '2026-08-07T09:05:00Z',
      kind: 'charge',
      title: 'بدهکار شد',
      description: 'فروش اعتباری',
      amount: 128_500_000,
      url: null,
      actor: null,
    },
    {
      occurred_at: '2026-08-05T14:20:00Z',
      kind: 'follow_up',
      title: 'پیگیری: تماس برای تحویل گارانتی',
      description: null,
      amount: null,
      url: null,
      actor: 'محمدرضا کریمی',
    },
    {
      occurred_at: '2026-08-04T10:00:00Z',
      kind: 'note',
      title: 'یادداشت',
      description: 'گفت هفته آینده برای تعویض باتری می‌آید.',
      amount: null,
      url: null,
      actor: 'مالک',
    },
    {
      occurred_at: '2026-08-01T07:30:00Z',
      kind: 'loyalty',
      title: 'کسب امتیاز',
      description: 'بابت خرید مرداد',
      amount: null,
      url: null,
      actor: null,
    },
  ];

  return (
    <Section
      alt={alt}
      title="Timeline"
      note="ریل عمودی از لبه شروعِ خواندن. مبلغ‌ها با کلمه «بدهکار/بستانکار» جهت می‌گیرند، نه با علامت منها."
    >
      <Row label="رویدادهای ترکیبی">
        <Timeline items={items} className="w-full" />
      </Row>

      <Row label="یک ماژول پاسخ نداد">
        <Timeline items={items.slice(0, 2)} failed={['Repairs']} className="w-full" />
      </Row>

      <Row label="خالی">
        <Timeline items={[]} className="w-full" />
      </Row>
    </Section>
  );
}
