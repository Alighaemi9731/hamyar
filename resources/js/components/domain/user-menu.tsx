import { Link, router } from '@inertiajs/react';
import { LogOutIcon, MonitorSmartphoneIcon, SettingsIcon, ShieldCheckIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AuthUser } from '@/types';

/**
 * The seven system roles, in Persian.
 *
 * The shared `auth.user.roles` prop carries the English keys the permission catalogue is
 * written in — `Owner`, `Cashier` — and a Persian interface that calls somebody "Owner" is
 * an interface that has leaked its own source code. Mapped here rather than server-side for
 * the same reason `StatusBadge` maps fifty statuses in the client: the label is a
 * presentation fact, and changing it should not be a prop-contract change.
 *
 * A custom role a shop invented falls through to its own name, which is already Persian
 * because a shop typed it.
 */
const ROLE_LABEL: Record<string, string> = {
  Owner: 'مالک',
  Manager: 'مدیر',
  Cashier: 'صندوق‌دار',
  Salesperson: 'فروشنده',
  Technician: 'تعمیرکار',
  Accountant: 'حسابدار',
  Warehousekeeper: 'انباردار',
};

/** First letter of the name, for the trigger. Persian has no case, so no upper-casing. */
function initial(name: string): string {
  return name.trim().charAt(0) || '؟';
}

/**
 * Who is signed in, and the way out.
 *
 * ## There was no way out
 *
 * `POST /logout` has existed since authentication did. Nothing in the interface has ever
 * pointed at it — `grep -rn "logout|خروج"` over every layout and component returned
 * nothing. A shopkeeper could sign in and could not sign out, on a device that might be
 * the shop counter's shared tablet.
 *
 * ## Logout is a POST, and stays one
 *
 * `router.post` rather than a link: the route is POST because logging out is a state
 * change and a GET would let any page on the internet sign a shopkeeper out with an
 * `<img>` tag. The menu item is a button, so the keyboard and a screen reader both read it
 * as an action rather than a destination.
 *
 * ## Not a confirmation
 *
 * Signing out is cheap to undo — sign back in — and a confirm dialog on a daily action is
 * the kind of friction that teaches people to click through dialogs without reading them.
 * The destructive styling is warning enough.
 */
export function UserMenu({ user }: { user: AuthUser }) {
  const role = user.roles[0];
  const roleLabel = role ? (ROLE_LABEL[role] ?? role) : null;

  return (
    // `dir` belongs on the Root for menu primitives, not on the Content — design-system
    // rule 3, and the type checker agrees.
    <DropdownMenu dir="rtl">
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={`حساب کاربری — ${user.name}`}
          title={user.name}
        >
          {/* A letter rather than an icon: on a shared counter device the point of this
              control is telling *which* of the shop's staff is currently signed in, and
              a generic person glyph answers that for nobody. */}
          <span
            aria-hidden
            className="flex size-7 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
          >
            {initial(user.name)}
          </span>
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="font-normal">
          <span className="block truncate font-medium">{user.name}</span>
          {roleLabel && (
            <span className="mt-0.5 block text-2xs text-muted-foreground">{roleLabel}</span>
          )}
          {user.mobile && (
            <span className="ltr-value mt-0.5 block truncate text-2xs text-muted-foreground">
              {user.mobile}
            </span>
          )}
        </DropdownMenuLabel>

        <DropdownMenuSeparator />

        <DropdownMenuItem asChild>
          <Link href="/settings">
            <SettingsIcon aria-hidden />
            تنظیمات
          </Link>
        </DropdownMenuItem>

        <DropdownMenuItem asChild>
          <Link href="/settings/two-factor">
            <ShieldCheckIcon aria-hidden />
            ورود دومرحله‌ای
          </Link>
        </DropdownMenuItem>

        <DropdownMenuItem asChild>
          <Link href="/settings/sessions">
            <MonitorSmartphoneIcon aria-hidden />
            دستگاه‌های واردشده
          </Link>
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        <DropdownMenuItem variant="destructive" onSelect={() => router.post('/logout')}>
          <LogOutIcon aria-hidden />
          خروج از حساب
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
