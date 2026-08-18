import { Link, usePage } from '@inertiajs/react';
import { MenuIcon, SearchIcon, StoreIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { Toaster, toast } from 'sonner';

import { AnnouncementBanner } from '@/components/domain/announcement-banner';
import { BranchSwitcher } from '@/components/domain/branch-switcher';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { NAVIGATION } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface AppShellProps {
  title?: string;
  /** Actions rendered at the end of the page header row. */
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * The tenant panel frame.
 *
 * RTL note: the sidebar comes first in the DOM and is therefore on the *start* side,
 * which in `dir="rtl"` is the right of the screen — where a Persian reader's eye and
 * thumb land. Everything positional here is logical (border-s, ms-, start-), so the
 * same markup would mirror correctly if a Latin locale were ever added.
 */
export function AppShell({ title, actions, children }: AppShellProps) {
  const { announcements } = usePage<SharedProps>().props;

  const { props } = usePage<SharedProps>();
  const { flash, features, tenant, location, branch } = props;

  // Flash messages arrive as props on the next visit; surfacing them as toasts keeps
  // every module from having to render its own alert bar.
  useEffect(() => {
    if (flash.success) toast.success(flash.success);
    if (flash.error) toast.error(flash.error);
    if (flash.warning) toast.warning(flash.warning);
    if (flash.info) toast.info(flash.info);
  }, [flash]);

  return (
    <div className="flex min-h-dvh bg-background">
      {/* Chrome, not content: `no-print` keeps the sidebar, topbar and toasts off
          paper, so a print layout inside the shell prints only its sheet. */}
      <aside className="glass no-print sticky top-0 hidden h-dvh w-72 shrink-0 flex-col border-e lg:flex">
        <ShopBadge name={tenant?.name ?? 'MobiShop'} subdomain={tenant?.subdomain ?? null} />
        <SidebarNav currentPath={location} features={features} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="glass no-print sticky top-0 z-sticky flex h-16 items-center gap-2 border-b px-4 sm:px-6">
          <Sheet>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="lg:hidden" aria-label="منو">
                <MenuIcon className="size-5" />
              </Button>
            </SheetTrigger>
            {/* In RTL, side="right" is the reading-start edge — the drawer slides in
                from the same side the desktop sidebar occupies. */}
            <SheetContent side="right" dir="rtl" className="w-72 p-0">
              <SheetTitle className="sr-only">منوی اصلی</SheetTitle>
              <ShopBadge name={tenant?.name ?? 'MobiShop'} subdomain={tenant?.subdomain ?? null} />
              <SidebarNav currentPath={location} features={features} />
            </SheetContent>
          </Sheet>

          {/* `min-w-0 shrink` overrides the base Button's `shrink-0`: without it the
              search field refuses to compress below its text width and pushes the
              theme toggle a hair past the viewport edge at 390px. */}
          <Button
            variant="outline"
            className="h-10 min-w-0 max-w-md shrink flex-1 justify-start gap-2 text-muted-foreground"
          >
            <SearchIcon className="size-4 shrink-0" />
            <span className="truncate text-sm">جستجوی کالا، مشتری، IMEI یا شماره قبض…</span>
          </Button>

          <div className="ms-auto flex items-center gap-1">
            {/* Renders nothing for a single-branch shop, which is almost every shop. */}
            <BranchSwitcher branch={branch} />
            <ThemeToggle />
          </div>
        </header>

        <main
          data-print-root
          className="mx-auto w-full max-w-(--container-shell) flex-1 px-4 py-10 sm:px-8 sm:py-14"
        >
          {(title || actions) && (
            <div className="no-print mb-10 flex flex-wrap items-center justify-between gap-4">
              {title && <h1 className="text-2xl font-bold">{title}</h1>}
              {/*
                `flex-wrap` is not cosmetic. The outer row wraps, so a long title moves
                the action group to its own line — and then the group itself, which did
                not wrap, ran off the edge: three buttons on the products list came to
                553px inside a 375px viewport and pushed the whole page sideways.
                Caught by the browser smoke suite (roadmap 11.1b) on its first honest
                run, on a screen that had been walked by hand several times.
              */}
              {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
          )}

          <div className="no-print">
            <AnnouncementBanner announcements={announcements ?? []} />
          </div>

          {children}
        </main>
      </div>

      <Toaster dir="rtl" position="bottom-left" richColors closeButton className="no-print" />
    </div>
  );
}

function ShopBadge({ name, subdomain }: { name: string; subdomain: string | null }) {
  return (
    <div className="flex h-16 items-center gap-2.5 border-b border-border px-5">
      <span className="flex size-9 items-center justify-center rounded-control bg-primary text-primary-foreground">
        <StoreIcon className="size-4" />
      </span>
      <span className="min-w-0">
        <span className="block truncate font-display text-sm font-bold">{name}</span>
        {subdomain && (
          <span className="ltr-value block truncate text-2xs text-muted-foreground" dir="ltr">
            {subdomain}
          </span>
        )}
      </span>
    </div>
  );
}

function SidebarNav({
  currentPath,
  features,
}: {
  currentPath: string;
  features: Record<string, boolean>;
}) {
  return (
    <nav className="flex-1 overflow-y-auto px-3 py-4">
      {NAVIGATION.map((section) => {
        // A section whose every item is gated off by the plan should disappear
        // entirely rather than leave an orphan heading.
        const visible = section.items.filter(
          (item) => item.feature === undefined || features[item.feature] !== false
        );

        if (visible.length === 0) {
          return null;
        }

        return (
          <div key={section.label} className="mb-4">
            <p className="px-3 pb-2 text-2xs font-medium tracking-wide text-muted-foreground">
              {section.label}
            </p>

            <ul className="space-y-0.5">
              {visible.map((item) => {
                const active = currentPath === item.href || currentPath.startsWith(`${item.href}/`);

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'flex items-center gap-3 rounded-pill px-3.5 text-sm transition-colors',
                        'h-[var(--density-row)]',
                        active
                          ? 'bg-primary/10 font-semibold text-primary'
                          : 'text-foreground/75 hover:bg-accent hover:text-foreground'
                      )}
                    >
                      <item.icon className="size-4 shrink-0" />
                      <span className="truncate">{item.label}</span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}
