import { Head, Link } from '@inertiajs/react';
import {
  ArrowRightIcon,
  BuildingIcon,
  CheckIcon,
  CopyIcon,
  FileTextIcon,
  ShieldCheckIcon,
  SmartphoneIcon,
  UserRoundIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali, toJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface DocumentRef {
  label: string;
  url: string | null;
}

interface Unit {
  id: number;
  imei1: string | null;
  imei2: string | null;
  serial: string | null;
  product_name: string;
  variant_name: string;
  status: string;
  condition: string;
  condition_label: string;
  uses_grade: boolean;
  grade: string | null;
  warehouse_name: string | null;
  branch_name: string | null;
  cost: MoneyValue | null;
  acquired_from: DocumentRef | null;
  acquired_at: string | null;
  hamta_status: string;
  hamta_activation_id: string | null;
  warranty_months: number | null;
  warranty_until: string | null;
  notes: string | null;
}

interface TimelineEvent {
  id: number;
  at: string;
  from_status: string | null;
  to_status: string;
  is_acquisition: boolean;
  actor: string | null;
  note: string | null;
  reference: DocumentRef | null;
}

interface Props {
  unit: Unit;
  timeline: TimelineEvent[];
  can: { view_cost: boolean };
}

/**
 * The IMEI passport — this product's signature screen.
 *
 * One device, one vertical read: where it came from, everywhere it went, what was done
 * to it, where it is now. The design decisions that matter are all about holding up
 * under the two things that break timelines:
 *
 * - **Long Persian names.** Nothing in the timeline truncates. A supplier called
 *   «پخش قطعات جنوب شرق تهران — شرکت تجارت الکترونیک آریا» wraps onto three lines and
 *   stays readable; cutting it with an ellipsis would hide the one fact the line
 *   exists to record.
 * - **Many events.** A handset that was transferred four times and repaired twice has
 *   twenty entries. They are grouped by Jalali day with the day named once, so the eye
 *   reads dates as headings rather than re-reading the same date twenty times, and
 *   each entry stays short enough that twenty of them are still one scroll.
 *
 * The rail sits on the reading-start edge (the right, in RTL) and every offset here is
 * logical, so the whole thing mirrors correctly if a Latin locale is ever added.
 */
export default function UnitPassport({ unit, timeline, can }: Props) {
  const code = unit.imei1 ?? unit.serial;

  return (
    <AppShell
      title={unit.product_name}
      actions={
        <Button variant="outline" asChild>
          <Link href="/inventory/units">
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت به فهرست دستگاه‌ها
          </Link>
        </Button>
      }
    >
      <Head title={`شناسنامه ${code ?? unit.product_name}`} />

      <Identity unit={unit} />

      {/* The timeline leads. In RTL the first grid column is the right-hand one, so
          the life story sits where the eye lands and the facts sit beside it. */}
      <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
        <Timeline events={timeline} />
        <Facts unit={unit} canViewCost={can.view_cost} />
      </div>
    </AppShell>
  );
}

/* --------------------------------------------------------------- identity -- */

/**
 * The device, identified once and identified big.
 *
 * The IMEI is the headline, not the model name. Everyone who ever asks about this
 * device — the customer, HAMTA, a warranty claim, a police report — identifies it by
 * that number, and the model name is already the page heading; repeating it here would
 * spend the most prominent line on something the reader just read.
 *
 * It renders LTR and tabular at a size that can be read across a counter, and it can
 * be copied without being retyped, because retyping fifteen digits is where
 * transcription errors come from.
 */
function Identity({ unit }: { unit: Unit }) {
  // Some devices genuinely have no IMEI — smartwatches, some tablets — and then the
  // serial is what identifies them, so it takes the headline instead.
  const headline = unit.imei1 ?? unit.serial;
  const headlineLabel = unit.imei1 === null ? 'شماره سریال' : 'شماره IMEI';

  return (
    <section className="rounded-card border border-border bg-card p-6 sm:p-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 space-y-1">
          <p className="text-2xs text-muted-foreground">{headlineLabel}</p>

          {headline === null ? (
            <p className="text-lg font-semibold text-warning">بدون شناسه</p>
          ) : (
            <p className="flex flex-wrap items-center gap-1">
              <Num value={headline} variant="ltr" className="text-lg font-semibold" />
              <CopyButton label={headlineLabel} value={headline} />
            </p>
          )}

          <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <SmartphoneIcon className="size-4 shrink-0" aria-hidden />
            <span className="break-words">{unit.variant_name}</span>
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={unit.status} />
          <StatusBadge status={`hamta_${unit.hamta_status}`} />
        </div>
      </div>

      <dl className="mt-6 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
        {unit.imei1 !== null && <CodeField label="شماره سریال" value={unit.serial} />}
        <CodeField label="IMEI ۲" value={unit.imei2} copyable />
        <div className="space-y-1">
          <dt className="text-2xs text-muted-foreground">وضعیت ظاهری</dt>
          <dd className="text-sm">
            {unit.condition_label}
            {unit.uses_grade && unit.grade && <span className="ms-1">· درجه {unit.grade}</span>}
            {/* A new sealed device has no cosmetic grade at all, which is different
                from having one nobody recorded. */}
            {unit.uses_grade && !unit.grade && (
              <span className="ms-1 text-muted-foreground">درجه ثبت نشده</span>
            )}
          </dd>
        </div>
      </dl>

      {headline === null && (
        <p className="mt-4 text-xs text-warning">
          این دستگاه نه IMEI دارد نه شماره سریال؛ ردیابی آن فقط از روی همین صفحه ممکن است.
        </p>
      )}
    </section>
  );
}

function CodeField({
  label,
  value,
  copyable = false,
}: {
  label: string;
  value: string | null;
  copyable?: boolean;
}) {
  if (value === null || value === '') {
    return (
      <div className="space-y-1">
        <dt className="text-2xs text-muted-foreground">{label}</dt>
        <dd className="text-sm text-muted-foreground">ثبت نشده</dd>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      <dt className="text-2xs text-muted-foreground">{label}</dt>
      <dd className="flex items-center gap-1">
        <Num value={value} variant="ltr" className="text-sm" />
        {copyable && <CopyButton label={label} value={value} />}
      </dd>
    </div>
  );
}

/**
 * Copy a code to the clipboard, and say so.
 *
 * The tick is the whole point: a copy button with no feedback gets pressed three times
 * because nobody can tell whether it worked.
 */
function CopyButton({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <Button
      variant="ghost"
      size="icon"
      aria-label={`کپی ${label}`}
      onClick={() => {
        void navigator.clipboard?.writeText(value);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
      }}
    >
      {copied ? <CheckIcon className="size-3.5 text-success" /> : <CopyIcon className="size-3.5" />}
    </Button>
  );
}

/* ------------------------------------------------------------------ facts -- */

function Facts({ unit, canViewCost }: { unit: Unit; canViewCost: boolean }) {
  return (
    <aside className="space-y-6 rounded-card border border-border bg-surface p-6 lg:sticky lg:top-24">
      <Fact icon={UserRoundIcon} label="خریداری‌شده از">
        {unit.acquired_from ? (
          unit.acquired_from.url ? (
            <Link href={unit.acquired_from.url} className="text-primary break-words">
              {unit.acquired_from.label}
            </Link>
          ) : (
            <span className="break-words">{unit.acquired_from.label}</span>
          )
        ) : (
          <span className="text-muted-foreground">ثبت نشده</span>
        )}
        {unit.acquired_at && (
          <span className="block text-2xs text-muted-foreground">
            {formatJalali(unit.acquired_at, { longMonth: true })}
          </span>
        )}
      </Fact>

      <Fact icon={BuildingIcon} label="محل نگهداری">
        {unit.warehouse_name ? (
          <>
            <span className="break-words">{unit.warehouse_name}</span>
            {unit.branch_name && (
              <span className="block text-2xs text-muted-foreground">{unit.branch_name}</span>
            )}
          </>
        ) : (
          <span className="text-muted-foreground">در هیچ انباری نیست</span>
        )}
      </Fact>

      {canViewCost && unit.cost && (
        <Fact icon={FileTextIcon} label="بهای خرید این دستگاه">
          <Money rial={unit.cost.value} withUnit unitPlacement="block" />
          <span className="block text-2xs text-muted-foreground">
            مخصوص همین دستگاه است، نه میانگین.
          </span>
        </Fact>
      )}

      <Fact icon={ShieldCheckIcon} label="گارانتی">
        {unit.warranty_until ? (
          <>
            <span>تا {formatJalali(unit.warranty_until, { longMonth: true })}</span>
            {unit.warranty_months && (
              <span className="block text-2xs text-muted-foreground">
                <Num value={unit.warranty_months} /> ماه از زمان فروش
              </span>
            )}
          </>
        ) : (
          <span className="text-muted-foreground">ثبت نشده</span>
        )}
      </Fact>

      {unit.hamta_activation_id && (
        <Fact icon={ShieldCheckIcon} label="کد رهگیری همتا">
          <Num value={unit.hamta_activation_id} variant="ltr" />
        </Fact>
      )}

      {unit.notes && (
        <Fact icon={FileTextIcon} label="یادداشت">
          <span className="break-words whitespace-pre-line">{unit.notes}</span>
        </Fact>
      )}
    </aside>
  );
}

function Fact({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof UserRoundIcon;
  label: string;
  children: ReactNode;
}) {
  return (
    <div className="flex gap-3">
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
      <div className="min-w-0 space-y-1">
        <p className="text-2xs text-muted-foreground">{label}</p>
        <div className="text-sm">{children}</div>
      </div>
    </div>
  );
}

/* --------------------------------------------------------------- timeline -- */

/**
 * What each transition is called, in the words a shop uses.
 *
 * Keyed `from→to`, falling back to a generic phrasing. Naming the event rather than
 * printing "موجود ← رزرو" is the difference between a log and a story: nobody reads a
 * passport to learn what the status column changed to, they read it to learn what
 * happened to the phone.
 */
const EVENT_TITLES: Record<string, string> = {
  'in_stock→reserved': 'برای مشتری رزرو شد',
  'reserved→in_stock': 'رزرو لغو شد',
  'in_stock→sold': 'فروخته شد',
  'reserved→sold': 'به مشتریِ رزروکننده فروخته شد',
  'returned→in_stock': 'پس از بررسی به موجودی برگشت',
  'sold→returned': 'مشتری دستگاه را مرجوع کرد',
  'in_stock→in_repair': 'به تعمیرگاه رفت',
  'reserved→in_repair': 'به تعمیرگاه رفت',
  'sold→in_repair': 'برای تعمیر برگشت',
  'returned→in_repair': 'برای بررسی به تعمیرگاه رفت',
  'in_repair→in_stock': 'از تعمیر برگشت و موجود شد',
  'in_repair→reserved': 'از تعمیر برگشت و رزرو ماند',
  'in_repair→sold': 'پس از تعمیر فروخته شد',
  'in_repair→returned': 'از تعمیر برگشت، به‌عنوان مرجوعی',
};

function titleFor(event: TimelineEvent): string {
  if (event.is_acquisition) {
    return 'وارد فروشگاه شد';
  }

  const key = `${event.from_status}→${event.to_status}`;

  if (EVENT_TITLES[key]) {
    return EVENT_TITLES[key];
  }

  if (event.to_status === 'written_off') {
    return 'ضایعات شد';
  }

  return 'وضعیت دستگاه تغییر کرد';
}

function Timeline({ events }: { events: TimelineEvent[] }) {
  if (events.length === 0) {
    // Not reachable through the application — a unit is created with its acquisition
    // line in the same transaction — so this says "the data is wrong", not "nothing
    // has happened yet". Those are different sentences and only one is honest here.
    return (
      <section className="rounded-card border border-dashed border-border p-10 text-center">
        <p className="text-sm font-medium">این دستگاه هیچ سابقه‌ای ندارد</p>
        <p className="mt-1 text-xs text-muted-foreground">
          هر دستگاه باید دست‌کم سطر ورود داشته باشد. اگر این صفحه را می‌بینید، سابقه ناقص ثبت شده
          است.
        </p>
      </section>
    );
  }

  const days = groupByDay(events);

  return (
    <section className="rounded-card border border-border bg-card p-6 sm:p-8">
      <h2 className="font-display text-base font-bold">سرگذشت دستگاه</h2>
      <p className="mt-1 text-xs text-muted-foreground">
        از قدیمی‌ترین رویداد به تازه‌ترین. این سطرها فقط اضافه می‌شوند و هرگز ویرایش نمی‌شوند.
      </p>

      <div className="mt-8 space-y-8">
        {days.map((day) => (
          <div key={day.key}>
            <h3 className="text-xs font-medium text-muted-foreground">{day.label}</h3>

            {/* The rail: a hairline on the reading-start edge, with each marker
                sitting on it. `ps-8` clears it for the content. */}
            <ol className="relative mt-3 space-y-5 border-s-0">
              <span aria-hidden className="absolute inset-y-1 start-[0.4375rem] w-px bg-border" />

              {day.events.map((event) => (
                <TimelineItem key={event.id} event={event} />
              ))}
            </ol>
          </div>
        ))}
      </div>
    </section>
  );
}

function TimelineItem({ event }: { event: TimelineEvent }) {
  return (
    <li className="relative ps-8">
      <span
        aria-hidden
        className={cn(
          'absolute start-1 top-1.5 size-2 rounded-full ring-4 ring-card',
          MARKER_TONE[event.to_status] ?? 'bg-muted-foreground'
        )}
      />

      <div className="space-y-1.5">
        {/* Nothing here truncates. A long supplier name or a three-line note is the
            content, not decoration around it. */}
        <p className="text-sm font-medium break-words">{titleFor(event)}</p>

        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-muted-foreground">
          <span className="tabular">{formatJalali(event.at, { withTime: true })}</span>
          {event.actor && (
            <>
              <span aria-hidden>·</span>
              <span className="break-words">{event.actor}</span>
            </>
          )}
          {!event.is_acquisition && (
            <>
              <span aria-hidden>·</span>
              <StatusBadge status={event.to_status} className="text-2xs" />
            </>
          )}
        </p>

        {event.reference && (
          <p className="text-xs">
            {event.reference.url ? (
              <Link href={event.reference.url} className="text-primary break-words">
                {event.reference.label}
              </Link>
            ) : (
              <span className="inline-flex items-center gap-1.5 rounded-pill bg-muted px-2.5 py-1 text-2xs break-words">
                <FileTextIcon className="size-3 shrink-0" aria-hidden />
                {event.reference.label}
              </span>
            )}
          </p>
        )}

        {event.note && (
          <p className="text-xs text-muted-foreground break-words whitespace-pre-line">
            {event.note}
          </p>
        )}
      </div>
    </li>
  );
}

const MARKER_TONE: Record<string, string> = {
  in_stock: 'bg-success',
  reserved: 'bg-warning',
  sold: 'bg-primary',
  in_repair: 'bg-info',
  returned: 'bg-warning',
  written_off: 'bg-destructive',
};

/**
 * Group consecutive events by Jalali day.
 *
 * By day, not by month: a device typically moves several times on the day it is sold
 * and then not for weeks, so the day is the unit that actually clusters. Grouping in
 * Tehran time rather than UTC matters — anything after 20:30 local would otherwise be
 * filed under tomorrow.
 */
function groupByDay(events: TimelineEvent[]): {
  key: string;
  label: string;
  events: TimelineEvent[];
}[] {
  const days: { key: string; label: string; events: TimelineEvent[] }[] = [];

  for (const event of events) {
    const { jy, jm, jd } = toJalali(event.at);
    const key = `${jy}-${jm}-${jd}`;
    const last = days[days.length - 1];

    if (last && last.key === key) {
      last.events.push(event);
    } else {
      days.push({
        key,
        label: formatJalali(event.at, { longMonth: true }),
        events: [event],
      });
    }
  }

  return days;
}
