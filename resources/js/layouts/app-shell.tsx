import { Link, usePage } from '@inertiajs/react';
import { MenuIcon, SearchIcon, StoreIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { Toaster, toast } from 'sonner';

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
  const { props } = usePage<SharedProps>();
  const { flash, features, tenant, location } = props;

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
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-e border-border bg-surface lg:flex">
        <ShopBadge name={tenant?.name ?? 'MobiShop'} subdomain={tenant?.subdomain ?? null} />
        <SidebarNav currentPath={location} features={features} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-sticky flex h-14 items-center gap-2 border-b border-border bg-surface/85 px-4 backdrop-blur">
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
            className="h-9 min-w-0 max-w-md shrink flex-1 justify-start gap-2 text-muted-foreground"
          >
            <SearchIcon className="size-4 shrink-0" />
            <span className="truncate text-xs">جستجوی کالا، مشتری، IMEI یا شماره قبض…</span>
          </Button>

          <div className="ms-auto flex items-center gap-1">
            <ThemeToggle />
          </div>
        </header>

        <main className="flex-1 px-4 py-6">
          {(title || actions) && (
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
              {title && <h1 className="text-lg">{title}</h1>}
              {actions && <div className="flex items-center gap-2">{actions}</div>}
            </div>
          )}

          {children}
        </main>
      </div>

      <Toaster dir="rtl" position="bottom-left" richColors closeButton />
    </div>
  );
}

function ShopBadge({ name, subdomain }: { name: string; subdomain: string | null }) {
  return (
    <div className="flex h-14 items-center gap-2 border-b border-border px-4">
      <span className="flex size-8 items-center justify-center rounded-control bg-primary text-primary-foreground">
        <StoreIcon className="size-4" />
      </span>
      <span className="min-w-0">
        <span className="block truncate font-display text-xs font-bold">{name}</span>
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
    <nav className="flex-1 overflow-y-auto px-2 py-3">
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
            <p className="px-3 pb-1 text-2xs font-medium text-muted-foreground">{section.label}</p>

            <ul className="space-y-0.5">
              {visible.map((item) => {
                const active =
                  currentPath === item.href || currentPath.startsWith(`${item.href}/`);

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'flex items-center gap-2.5 rounded-control px-3 text-xs transition-colors',
                        'h-[var(--density-row)]',
                        active
                          ? 'bg-accent font-medium text-accent-foreground'
                          : 'text-foreground/80 hover:bg-muted hover:text-foreground'
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
