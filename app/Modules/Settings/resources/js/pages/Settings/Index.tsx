import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
  ArrowLeftIcon,
  CreditCardIcon,
  HistoryIcon,
  MonitorSmartphoneIcon,
  SettingsIcon,
  ShieldCheckIcon,
  StoreIcon,
  UsersIcon,
} from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Card } from '@/components/ui/card';
import { AppShell } from '@/layouts/app-shell';

interface Destination {
  key: string;
  title: string;
  description: string;
  href: string;
}

interface Props {
  groups: { key: string; label: string; items: Destination[] }[];
}

/**
 * One glyph per door, keyed by the catalogue's destination key. The catalogue is
 * server-side and permission-filtered; the glyphs live here because they are the
 * screen's, not the data's. A door added without one gets the cog until it is named.
 */
const ICONS: Record<string, LucideIcon> = {
  users: UsersIcon,
  branches: StoreIcon,
  billing: CreditCardIcon,
  'two-factor': ShieldCheckIcon,
  sessions: MonitorSmartphoneIcon,
  activity: HistoryIcon,
};

/**
 * The settings hub.
 *
 * ## What was here before
 *
 * A 404. The sidebar has carried a «تنظیمات» item pointing at `/settings` since it was
 * written, and the Settings module's routes file contained nothing but a comment block —
 * so every user, on every page, had a nav item that failed. The screens themselves all
 * existed; they were scattered across four modules with no single door, and a shop found
 * them by being sent a link.
 *
 * ## A description under every title
 *
 * «کاربران و نقش‌ها» and «دستگاه‌های واردشده» both sound like "who can get in", and a
 * shopkeeper looking for "stop my old employee logging in" has to guess which. The line
 * under each title is what stops that being a guess — the same reasoning the report index
 * records, and the same reason neither screen ships a bare list of links.
 *
 * ## No rows for things you cannot open
 *
 * The catalogue filters by permission and drops groups that empty out, rather than
 * greying rows. A disabled row invites the question "why not?", which the screen cannot
 * answer; an absent one is simply not part of this person's job.
 */
export default function SettingsIndex({ groups }: Props) {
  return (
    <AppShell title="تنظیمات">
      <Head title="تنظیمات" />

      {groups.length === 0 ? (
        <EmptyState
          variant="permission"
          title="تنظیماتی در دسترس شما نیست"
          description="حساب شما به هیچ‌کدام از بخش‌های تنظیمات دسترسی ندارد. اگر باید کاربران یا اشتراک فروشگاه را ببینید، از مدیر فروشگاه بخواهید دسترسی‌تان را تغییر دهد."
        />
      ) : (
        <div className="space-y-10">
          {groups.map((group) => (
            <section key={group.key}>
              <h2 className="mb-4 text-sm font-semibold text-muted-foreground">{group.label}</h2>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {group.items.map((item) => {
                  const Icon = ICONS[item.key] ?? SettingsIcon;

                  return (
                    <Card key={item.key} asChild interactive className="group">
                      <Link href={item.href}>
                        <div className="flex items-start justify-between gap-3">
                          <span className="flex min-w-0 items-center gap-3">
                            <span
                              aria-hidden
                              className="flex size-9 shrink-0 items-center justify-center rounded-control bg-accent text-accent-foreground"
                            >
                              <Icon className="size-4" />
                            </span>
                            <h3 className="font-semibold">{item.title}</h3>
                          </span>
                          {/* The arrow points to the reading end, which in RTL is the
                              physical left — `rtl:rotate-180` would send it backwards. */}
                          <ArrowLeftIcon
                            className="mt-2.5 size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-brand"
                            aria-hidden
                          />
                        </div>
                        <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                          {item.description}
                        </p>
                      </Link>
                    </Card>
                  );
                })}
              </div>
            </section>
          ))}
        </div>
      )}
    </AppShell>
  );
}
