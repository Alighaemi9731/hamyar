import { Link, usePage } from '@inertiajs/react';
import { MenuIcon, PanelRightCloseIcon, PanelRightOpenIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { BrandMark } from '@/components/brand-mark';
import { AnnouncementBanner } from '@/components/domain/announcement-banner';
import { PageHeader } from '@/components/domain/page-header';
import { QuotaBlock } from '@/components/domain/quota-block';
import { UsageBanner } from '@/components/domain/usage-banner';
import { BranchSwitcher } from '@/components/domain/branch-switcher';
import { CommandPalette } from '@/components/domain/command-palette';
import { UserMenu } from '@/components/domain/user-menu';
import { ThemeToggle } from '@/components/theme-toggle';
import { Toaster } from '@/components/ui/sonner';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { NAVIGATION } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * `title` **or** `header`, never both — and the union is what enforces it.
 *
 * Thirteen pages currently render a second `<h1>` under the shell's, or skip `title` and
 * hand-roll one. Both come from the same gap: a screen that needs an eyebrow, a
 * description, a back link or a row of badges has nowhere to put them, so it builds its
 * own header and the shell's becomes a duplicate.
 *
 * `<PageHeader>` is that slot. Written as a discriminated union so passing both is a
 * compile error rather than a review comment — the one-heading-per-page rule is the sort
 * that gets broken by people who never read the rule.
 */
type AppShellProps = {
  children: ReactNode;
  /**
   * How wide the page may run. `default` is the 1110px reading column every screen
   * gets; `wide` (1400px) is for the two families that are worked, not read — the
   * counter, where the basket and the catalogue share the width, and the reports,
   * whose tables run to thirty columns. A form or a register on `wide` is a line
   * length nobody can follow, so it is opt-in per page, never the default.
   */
  width?: 'default' | 'wide';
} & (
  | {
      title?: string;
      /** Actions rendered at the end of the page header row. */
      actions?: ReactNode;
      header?: never;
    }
  | {
      /** A `<PageHeader>`. Owns the page's `<h1>`, so the shell renders none. */
      header: ReactNode;
      title?: never;
      actions?: never;
    }
);

/** Where the rail remembers itself. Per browser, on purpose: the counter PC and the phone differ. */
const RAIL_KEY = 'hamyar.sidebar';

function readRail(): boolean {
  try {
    return window.localStorage.getItem(RAIL_KEY) === 'rail';
  } catch {
    return false;
  }
}

function storeRail(collapsed: boolean): void {
  try {
    window.localStorage.setItem(RAIL_KEY, collapsed ? 'rail' : 'full');
  } catch {
    // A private window or a locked-down browser: the toggle still works for the session.
  }
}

/**
 * The tenant panel frame.
 *
 * RTL note: the sidebar comes first in the DOM and is therefore on the *start* side,
 * which in `dir="rtl"` is the right of the screen — where a Persian reader's eye and
 * thumb land. Everything positional here is logical (border-s, ms-, start-), so the
 * same markup would mirror correctly if a Latin locale were ever added.
 *
 * ## The rail
 *
 * The sidebar collapses to one icon wide (`--sidebar-rail`). The counter and the
 * reports are worked, not read, and on a 1280px laptop the full sidebar costs the POS a
 * fifth of its width for a list of links the cashier stopped reading on day two. Each
 * link keeps its name for a screen reader and gains a tooltip for the pointer; the
 * choice is remembered per browser, because the counter PC and the owner's phone are
 * different places. The drawer below `lg` is untouched — a phone has no rail.
 */
export function AppShell({ title, actions, header, width = 'default', children }: AppShellProps) {
  const { announcements } = usePage<SharedProps>().props;

  const { props } = usePage<SharedProps>();
  const { auth, flash, features, tenant, location, branch, usage, quota_block: quotaBlock } = props;

  // Read once, on the client, before the first paint of this tree: SSR is off, so the
  // initialiser is the earliest moment there is a `window` and the latest that avoids
  // a full-to-rail jump on every navigation.
  const [collapsed, setCollapsed] = useState<boolean>(readRail);

  function toggleRail(): void {
    setCollapsed((current) => {
      storeRail(!current);

      return !current;
    });
  }

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
      {/*
        First focusable thing in the document, and invisible until it is focused.

        Every screen puts eighteen navigation links between the top of the page and the
        content, and a keyboard user had to walk all of them on every visit. The landing
        page has had one of these since it shipped; the application a shopkeeper uses all
        day did not.
      */}
      <a
        href="#main"
        className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:top-3 focus-visible:start-3 focus-visible:z-toast focus-visible:inline-flex focus-visible:min-h-10 focus-visible:items-center focus-visible:rounded-pill focus-visible:bg-primary focus-visible:px-4 focus-visible:text-sm focus-visible:text-primary-foreground"
      >
        پرش به محتوا
      </a>

      {/* Chrome, not content: `no-print` keeps the sidebar, topbar and toasts off
          paper, so a print layout inside the shell prints only its sheet. */}
      {/* `--sidebar-width` is the one number the rail, the drawer and the page's
          arithmetic share; a rail that is 288px in one place and 256px in another is
          how a drawer stops matching the sidebar it stands in for. */}
      <aside
        data-rail={collapsed ? 'collapsed' : 'open'}
        className={cn(
          'glass no-print sticky top-0 hidden h-dvh shrink-0 flex-col border-e transition-[width] duration-(--duration-base) ease-(--ease-out) lg:flex',
          collapsed ? 'w-(--sidebar-rail)' : 'w-(--sidebar-width)'
        )}
      >
        <ShopBadge
          name={tenant?.name ?? 'سامانه همیار'}
          subdomain={tenant?.subdomain ?? null}
          compact={collapsed}
        />
        <SidebarNav currentPath={location} features={features} compact={collapsed} />

        <div className="border-t border-border p-2">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={toggleRail}
                aria-expanded={!collapsed}
                aria-controls="sidebar-nav"
                aria-label={collapsed ? 'بازکردن منو' : 'جمع‌کردن منو'}
                className={cn('w-full', !collapsed && 'justify-start px-3')}
              >
                {/* The panel glyphs are drawn for an LTR frame; the sidebar sits on the
                    right here, so "close" points into the rail the way the eye expects. */}
                {collapsed ? (
                  <PanelRightOpenIcon className="size-4" aria-hidden />
                ) : (
                  <PanelRightCloseIcon className="size-4" aria-hidden />
                )}
                {!collapsed && <span className="text-xs text-muted-foreground">جمع‌کردن منو</span>}
              </Button>
            </TooltipTrigger>
            {collapsed && <TooltipContent side="left">بازکردن منو</TooltipContent>}
          </Tooltip>
        </div>
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
            {/* The sheet sets its width under `data-[side=right]:`, so the token has to
                arrive under the same variant to replace it; a bare `w-*` here — the old
                `w-72` included — loses to that rule and the drawer stays at 75%. */}
            <SheetContent
              side="right"
              dir="rtl"
              className="p-0 data-[side=right]:w-(--sidebar-width)"
            >
              <SheetTitle className="sr-only">منوی اصلی</SheetTitle>
              <ShopBadge
                name={tenant?.name ?? 'سامانه همیار'}
                subdomain={tenant?.subdomain ?? null}
              />
              <SidebarNav currentPath={location} features={features} />
            </SheetContent>
          </Sheet>

          {/*
            This was a `<Button>` with a magnifier, a placeholder and no `onClick` — the
            most prominent control in the product, on every screen, doing nothing. It now
            opens a palette; see `command-palette.tsx` for what it searches and why the
            placeholder is narrower than the one it replaces.
          */}
          <CommandPalette features={features} />

          <div className="ms-auto flex items-center gap-1">
            {/* Renders nothing for a single-branch shop, which is almost every shop. */}
            <BranchSwitcher branch={branch} />
            <ThemeToggle />
            {/*
              The way out. `POST /logout` has existed since authentication did and nothing
              in the interface has ever pointed at it — a shopkeeper could sign in and could
              not sign out, on what is often a shared counter device.
            */}
            {auth.user && <UserMenu user={auth.user} />}
          </div>
        </header>

        <main
          id="main"
          data-print-root
          className={cn(
            'mx-auto w-full flex-1 px-4 py-10 sm:px-8 sm:py-14',
            width === 'wide' ? 'max-w-(--container-wide)' : 'max-w-(--container-shell)'
          )}
        >
          {/* A page-supplied header owns the `<h1>`; the shell's own row is skipped
              entirely rather than rendered empty. */}
          {header}

          {/*
            The `title` form is the same `<PageHeader>` a page would build itself, not a
            second rendering of it. There used to be two: this row and `page-header.tsx`,
            written to match and free to drift — a size or a wrap fix landing in one and
            not the other, on the one piece of chrome every screen shares. One
            implementation, so the 40px-title lesson and the 553px-wrap lesson recorded in
            `page-header.tsx` hold on every page, whichever form it uses.
          */}
          {!header && title && <PageHeader title={title} actions={actions} />}

          <div className="no-print">
            {/* The refusal first: it is about what the operator just tried to do, and it
                has to be the thing their eye lands on. Rendered here rather than in each
                form because most forms render only the error keys they were written to
                expect — see QuotaBlock's docblock. */}
            <QuotaBlock block={quotaBlock} />
            <UsageBanner usage={usage} />
            <AnnouncementBanner announcements={announcements ?? []} />
          </div>

          {children}
        </main>
      </div>

      {/*
        `@/components/ui/sonner`, not `sonner`.

        This rendered sonner's own `Toaster` directly, which meant the project's wrapper —
        the one carrying the lucide icons the rest of the app uses, the `--popover` /
        `--border` / `--radius` token bindings, and the theme — had **no consumers at all**.
        It was dead code that looked like the mechanism, so every toast in the product came
        out with sonner's default icons, sonner's default surface, and `theme="system"`:
        following the operating system rather than the switch in the header above it.
      */}
      <Toaster dir="rtl" position="bottom-left" richColors closeButton className="no-print" />
    </div>
  );
}

function ShopBadge({
  name,
  subdomain,
  compact = false,
}: {
  name: string;
  subdomain: string | null;
  compact?: boolean;
}) {
  return (
    <div
      className={cn(
        'flex h-16 items-center gap-2.5 border-b border-border',
        compact ? 'justify-center px-0' : 'px-5'
      )}
    >
      {/*
        The shop's name leads and the product's wordmark sits under it, quiet: this
        panel belongs to the shop, and Hamyar is where it is hosted. On the rail there
        is room for one of them, and it is the wordmark — the shop already knows whose
        counter it is standing at, and the rail's job is to be recognisable at a glance.
      */}
      {compact ? (
        <BrandMark className="h-3.5 text-primary" />
      ) : (
        <span className="min-w-0">
          <span className="block truncate font-display text-sm font-bold">{name}</span>
          <span className="mt-0.5 flex items-center gap-1.5">
            <BrandMark className="h-2.5 text-muted-foreground" />
            {subdomain && (
              <span className="ltr-value truncate text-2xs text-muted-foreground/70" dir="ltr">
                · {subdomain}
              </span>
            )}
          </span>
        </span>
      )}
    </div>
  );
}

function SidebarNav({
  currentPath,
  features,
  compact = false,
}: {
  currentPath: string;
  features: Record<string, boolean>;
  /** The rail: icons only, names in tooltips and for screen readers, sections as rules. */
  compact?: boolean;
}) {
  const activeHref = NAVIGATION.flatMap((section) => section.items)
    .map((item) => item.href)
    .filter((href) => currentPath === href || currentPath.startsWith(`${href}/`))
    .sort((a, b) => b.length - a.length)[0];

  return (
    <nav id="sidebar-nav" className={cn('flex-1 overflow-y-auto py-4', compact ? 'px-2' : 'px-3')}>
      {/*
        One active item, and it is the most specific one. Matching each item by prefix on
        its own lit two at once — on `/inventory/units/6` both «انبار» and «شناسنامهٔ IMEI»
        — because `/inventory` is a prefix of `/inventory/units`. The longest href that
        matches is the page you are on; its parents are not.
      */}
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
          <div key={section.label} className={cn(compact ? 'mb-2' : 'mb-4')}>
            {/* On the rail a section is a rule, not a word: there is no room for the
                label and the grouping still reads. The group keeps its name for a
                screen reader.

                `px-2` matches the rows below it. The chip is now the leading edge of
                every item, so the heading lines up with the tiles rather than with the
                text they push inward. */}
            <p
              className={cn(
                'text-2xs font-medium tracking-wide text-muted-foreground',
                compact ? 'sr-only' : 'px-2 pb-2'
              )}
            >
              {section.label}
            </p>
            {compact && <div aria-hidden className="mx-2 mb-2 border-t border-border" />}

            <ul className="space-y-0.5">
              {visible.map((item) => {
                const active = item.href === activeHref;

                const link = (
                  <Link
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'group flex items-center rounded-pill text-sm transition-colors',
                      'h-[var(--density-row)]',
                      // The tile carries its own inset, so the row's is smaller than it
                      // was: 8px of row padding plus a 32px chip puts the mark where the
                      // bare 16px glyph used to start, and the label lands in the same
                      // place it always did.
                      compact ? 'justify-center px-0' : 'gap-2.5 px-2',
                      active
                        ? 'bg-primary/8 font-semibold text-primary'
                        : 'text-foreground/75 hover:bg-accent hover:text-foreground'
                    )}
                  >
                    {/*
                      The mark. `aria-hidden` because it says nothing a screen reader
                      needs — the label beside it is the accessible name in both states,
                      and stays in the DOM as `sr-only` on the rail (asserted by
                      `tests/Browser/ShellTest.php`, which counts links with no text).

                      Three states, one accent: a neutral tile at rest so nineteen of
                      them do not shout, the accent tinting under the pointer, and the
                      accent filled solid on the page you are on. The reference product
                      gets this escalation from a different hue per destination; we get
                      it from one, because a second accent is a bug here.
                    */}
                    <span
                      aria-hidden
                      data-active={active}
                      className={cn(
                        'nav-chip grid size-8 shrink-0 place-items-center rounded-inner',
                        'motion-safe:group-hover:scale-105',
                        active
                          ? 'bg-primary text-primary-foreground'
                          : 'bg-foreground/10 text-muted-foreground group-hover:bg-primary/12 group-hover:text-primary'
                      )}
                    >
                      {/*
                        18px inside a 32px tile, and a heavier stroke than lucide's
                        default. Both are about weight: the reference's glyphs are
                        *filled*, lucide's are 2px outlines on a 24 grid — which lands at
                        1.5px once scaled to 18 and reads as a wireframe next to a solid
                        Material shape. 2.25 buys back most of that without turning the
                        smaller glyphs into blobs.
                      */}
                      <item.icon className="size-4.5" strokeWidth={2.25} aria-hidden />
                    </span>
                    <span className={cn('truncate', compact && 'sr-only')}>{item.label}</span>
                  </Link>
                );

                return (
                  <li key={item.href}>
                    {compact ? (
                      <Tooltip>
                        <TooltipTrigger asChild>{link}</TooltipTrigger>
                        {/* Physical left: the rail is on the right of the screen. */}
                        <TooltipContent side="left">{item.label}</TooltipContent>
                      </Tooltip>
                    ) : (
                      link
                    )}
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
